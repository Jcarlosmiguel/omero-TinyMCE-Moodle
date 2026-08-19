// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Authoring tool interactivity: reads the live preview iframe's own on-screen
 * position/zoom readout (same-origin, since it's served through our own proxy.php)
 * and uses it to build view-links against the current text selection, then
 * assembles the final embed HTML. See local_omeroembed's own plan doc for why
 * these two specific selectors are the right ones to read.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    // Read from the inline JSON element author.php prints as part of the ordinary
    // page body, not a window global set via $PAGE->requires->js_init_code() - see
    // the comment where author.php prints #omero-embed-config for why.
    var configEl = document.getElementById('omero-embed-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    // Per-placement token for the student-annotations feature (see
    // js/annotate.js and its own plan doc) - identifies this specific embed
    // *placement* (this quiz question, this Page, ...), independent of the
    // OMERO image id, since the same image can be embedded in more than one
    // place with independent annotations. config.embedAnnotateId is set by
    // author.php only when re-opening an *existing* embed for editing (see
    // tiny_omeroembed's ui.js reading the wrapper's own data-omero-annotate-id
    // back) - reusing it there instead of minting a new one is what stops a
    // teacher's edit from silently orphaning that embed's existing student
    // annotations. Minted once per authoring session and cached, not
    // re-minted on every generateEmbed() call.
    var embedAnnotateId = config.embedAnnotateId || null;

    /**
     * Escapes a string for safe use inside an HTML attribute value.
     *
     * generateEmbed() below builds the final embed HTML - the string that
     * gets saved into course content and rendered to every student who
     * views it - by string concatenation, not DOM APIs. width/height come
     * from plain text inputs (author.php's optional_param(..., PARAM_TEXT),
     * no format restriction either side) and are read live from those
     * inputs' current values, not from a re-validated source - a value
     * containing a quote and angle bracket breaks out of the style
     * attribute and injects arbitrary markup into stored content. Every
     * value concatenated into iframeHtml/inner/html below must go through
     * this first, not just the ones already known to be attacker-reachable
     * today - config.iframeName is server-regex-restricted to alphanumeric
     * (see author.php's own $iframename) but is escaped here too, since
     * this function being the one uniform gate is what stops it staying
     * safe only by accident of what author.php happens to do today.
     *
     * @param {string} value
     * @return {string}
     */
    function escapeHtmlAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * @return {string} A stable token identifying this specific embed
     *                   placement - reused from an existing embed being
     *                   edited if there is one, otherwise minted once and
     *                   cached for the rest of this authoring session.
     */
    function getOrMintAnnotateId() {
        if (!embedAnnotateId) {
            if (window.crypto && window.crypto.randomUUID) {
                embedAnnotateId = window.crypto.randomUUID();
            } else {
                // Fallback for older/embedded browser contexts lacking
                // crypto.randomUUID() - not cryptographically strong, but
                // this is an opaque grouping key, not a security boundary.
                embedAnnotateId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = Math.random() * 16 | 0;
                    var v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            }
        }
        return embedAnnotateId;
    }

    /**
     * Reads the live iviewer iframe's actual pan/zoom state via its own
     * <ol3-viewer> Aurelia component, calling the same Viewer instance the
     * app itself uses (viewer.getViewParameters(), an internal-but-public
     * method on ome/ol3-viewer's Viewer class - element.au.controller.
     * viewModel.viewer is Aurelia's own convention for reaching a custom
     * element's live view-model, not something this plugin invented).
     * getViewParameters() returns {center: [x, y], resolution, ...}; OL's
     * map uses a Y-up coordinate system while image pixels are Y-down, so
     * center[1] needs negating, and resolution converts to the same "zoom
     * percentage" iviewer's own .ol-zoom-display shows via
     * round(1 / resolution * 100) (lifted from displayResolutionInPercent()
     * in the built iviewer JS - kept unrounded here for precision).
     *
     * Two things were tried and rejected before this, both confirmed wrong
     * via live testing: (1) .intensity-display's text - iviewer's
     * mouse-hover pixel/value readout, not the pan position, so it only
     * ever reflected wherever the cursor last sat (blank with no hover at
     * all); (2) the iframe's own contentWindow.location.href - iviewer
     * does NOT keep its address in sync with pan/zoom as you interact
     * (confirmed live: zoom changed for real, address stayed static).
     * Both were the actual cause of a reported "saved position doesn't
     * match" bug - captured x/y were never the real pan state.
     *
     * Returns null if the iframe hasn't loaded a real view yet, or if
     * iviewer's internal structure doesn't match what's expected here
     * (e.g. after an iviewer upgrade) - degrades to the existing "wait for
     * it to finish loading" prompt rather than throwing.
     */
    function readCurrentView() {
        var iframe = document.getElementById(config.iframeId);
        var doc;
        try {
            doc = iframe.contentDocument;
        } catch (e) {
            return null;
        }
        if (!doc) {
            return null;
        }

        var viewerEl = doc.querySelector('ol3-viewer');
        if (!viewerEl || !viewerEl.au || !viewerEl.au.controller) {
            return null;
        }

        var viewer = viewerEl.au.controller.viewModel.viewer;
        var params;
        try {
            params = viewer.getViewParameters();
        } catch (e) {
            return null;
        }
        if (!params || !params.center || !params.resolution) {
            return null;
        }

        return {
            x: Math.round(params.center[0]),
            y: Math.round(-params.center[1]),
            zm: (1 / params.resolution * 100)
        };
    }

    function buildViewUrl(view) {
        var url = new URL(config.baseProxyUrl, window.location.href);
        url.searchParams.set('x', view.x);
        url.searchParams.set('y', view.y);
        url.searchParams.set('zm', view.zm);
        // Root-relative, not url.toString() (absolute) - this gets inserted
        // directly into stored write-up content via insertViewLink() below,
        // so baking in the current host/port would silently break every
        // view-link the moment Moodle's own address ever changes (a new
        // domain, a different port, an HTTP->HTTPS migration...). A root-
        // relative URL instead resolves correctly against whatever host is
        // actually serving the page at click time, forever.
        return url.pathname + url.search;
    }

    /**
     * Which layout is in effect *right now* - not necessarily config.layout,
     * which only reflects the page's initial GET params. Switching layout is a
     * client-side-only toggle (see applyLayout()), so anything that needs to
     * know the current arrangement (generateEmbed()) has to check the actual
     * checked radio, not the stale config value from page load.
     */
    function currentLayout() {
        var checked = document.querySelector('input[name="layout"]:checked');
        return checked ? checked.value : config.layout;
    }

    // Matches author.php's own $overlaysettings key list (proxy.php's
    // resolve_overlay_setting() is the actual precedence logic - this just
    // needs to read whatever the teacher currently has checked).
    // enablehotspot/enablehotspotmulti aren't here - they're a dropdown
    // now, not checkboxes (see author.php's own $hotspotmode comment) -
    // currentHotspotMode() below reads that instead.
    var OVERLAY_KEYS = [
        'hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar', 'showomerorois', 'enableannotations',
    ];

    /**
     * Same "read the live DOM, not a stale config snapshot" idea as
     * currentLayout() above, for width/height/overlay-checkboxes/colour-
     * swatches - all plain form fields that, unlike subject/image/dataset
     * (which genuinely need a server round trip via "Load slide" to load a
     * different image), only need their current value read at the moment
     * the embed is actually generated. Confirmed as a real bug: generateEmbed()
     * used to build the final iframe src from config.baseProxyUrl, which is
     * a PHP string computed once at the last page load/"Load slide" submit -
     * changing a colour swatch or overlay checkbox afterwards and clicking
     * Insert/Generate straight away silently kept the old values, since
     * that button never resubmits the form.
     *
     * @return {string}
     */
    function currentWidth() {
        var input = document.querySelector('input[name="width"]');
        return (input && input.value) ? input.value : config.maxWidth;
    }

    /** @return {string} */
    function currentHeight() {
        var input = document.querySelector('input[name="height"]');
        if (input && input.value) {
            return input.value;
        }
        return document.getElementById(config.iframeId).style.height;
    }

    /** @return {object} key => boolean, only for checkboxes actually present on the page. */
    function currentOverlaySettings() {
        var settings = {};
        OVERLAY_KEYS.forEach(function(key) {
            var checkbox = document.querySelector('input[type="checkbox"][name="' + key + '"]');
            if (checkbox) {
                settings[key] = checkbox.checked;
            }
        });
        return settings;
    }

    /**
     * @return {string} '', 'single', or 'multi' - see author.php's own
     *                  $hotspotmode comment. Always '' unless the current
     *                  layout is text-below, regardless of what the (now
     *                  hidden, not reset) dropdown itself still says - this
     *                  is the one place that rule is actually enforced, not
     *                  just visually hidden. Deliberately not resetting the
     *                  dropdown's own value when a teacher switches layout
     *                  away from text-below: it comes back correctly if
     *                  they switch back, rather than losing a choice just
     *                  for exploring layouts. Every caller (generateEmbed(),
     *                  applyHotspotModeUI(), the preview-reload logic below)
     *                  goes through this function rather than reading the
     *                  select directly, so the rule can't be half-applied.
     */
    function currentHotspotMode() {
        if (currentLayout() !== 'textbelow') {
            return '';
        }
        var select = document.querySelector('select[name="hotspotmode"]');
        return select ? select.value : '';
    }

    /**
     * @return {?string[]} Selected colours' real mixed-case hex values (read
     *                     from each checkbox's own data-hex - see author.php's
     *                     comment on why not reconstructed from the name
     *                     attribute), or null if the colour picker isn't on
     *                     this page at all (an embed generated before this
     *                     feature existed would still have none of this).
     */
    function currentColours() {
        var container = document.getElementById('omero-annotation-colours');
        if (!container) {
            return null;
        }
        return Array.prototype.slice.call(container.querySelectorAll('input[type="checkbox"]'))
            .filter(function(cb) {
                return cb.checked;
            })
            .map(function(cb) {
                return cb.getAttribute('data-hex');
            });
    }

    /**
     * Switches between "slide+write-up side by side" and "slide only" purely in
     * the DOM - no form submission, no page reload. Reloading to apply a layout
     * change would wipe out whatever the teacher had already typed in the
     * write-up box, since that content only ever exists client-side until
     * "Generate embed HTML" reads it - a plain hide/show and flex-direction
     * swap keeps it intact regardless of how many times layout is changed.
     */
    function applyLayout(layout) {
        var pane = document.getElementById('omero-split-pane');
        var writeup = document.getElementById(config.writeupId);
        var insertBtn = document.getElementById('omero-insert-link-btn');
        var removeBtn = document.getElementById('omero-remove-link-btn');
        var iframeWrap = document.getElementById('omero-iframe-wrap');
        // Defence in depth, not the actual fix: author.php now renders the
        // layout radios themselves disabled until a slide is loaded (the
        // real fix - a disabled radio can't fire a change event at all), so
        // this should be unreachable in practice. Kept anyway in case that
        // ever regresses - every element above lives inside author.php's own
        // $hasslide-gated block, so all of them are null before a slide
        // loads, and the old behaviour here was an uncaught exception on
        // whichever line touched one first, not a clean no-op.
        if (!pane || !writeup || !insertBtn || !removeBtn || !iframeWrap) {
            return;
        }

        // Keeps the :empty::before placeholder (see author.php's own CSS
        // rule and the div's initial data-placeholder, both near
        // #omero-writeup) in sync with whichever layout is now current -
        // doesn't matter that image-only never shows this box at all, the
        // attribute is harmless either way.
        writeup.dataset.placeholder = (layout === 'textbelow')
            ? config.strings.questiontextplaceholder
            : config.strings.writeupplaceholder;

        if (layout === 'imageonly') {
            writeup.style.display = 'none';
            insertBtn.style.display = 'none';
            removeBtn.style.display = 'none';
            iframeWrap.style.flex = '1 1 100%';
            pane.style.flexDirection = 'row';
        } else if (layout === 'textbelow') {
            // Stacked, not side-by-side - a short question under the image,
            // not a full-height write-up pane next to it.
            writeup.style.display = '';
            insertBtn.style.display = '';
            removeBtn.style.display = '';
            writeup.style.flex = '0 0 auto';
            writeup.style.width = '100%';
            writeup.style.height = 'auto';
            writeup.style.minHeight = '3em';
            iframeWrap.style.flex = '0 0 auto';
            iframeWrap.style.width = '100%';
            pane.style.flexDirection = 'column';
        } else {
            writeup.style.display = '';
            insertBtn.style.display = '';
            removeBtn.style.display = '';
            writeup.style.flex = '1';
            writeup.style.width = '';
            writeup.style.height = document.getElementById(config.iframeId).style.height;
            writeup.style.minHeight = '';
            iframeWrap.style.flex = '1';
            iframeWrap.style.minWidth = '0';
            pane.style.flexDirection = layout === 'slideright' ? 'row-reverse' : 'row';
        }

        // Hotspot mode only applies to the text-below layout (see
        // author.php's own comment on #omero-hotspot-mode-wrap for why) -
        // show/hide the control itself here, then re-run the two functions
        // that depend on currentHotspotMode()'s resolved value (defined
        // further below in this file - safe to call from here regardless of
        // definition order, both are function declarations, not
        // expressions, so they're hoisted) so the annotations checkbox and
        // the live preview both catch up immediately, not just on the next
        // time the dropdown itself happens to change.
        if (hotspotModeWrap) {
            hotspotModeWrap.style.display = (layout === 'textbelow') ? '' : 'none';
        }
        applyHotspotModeUI();
        reloadPreviewForHotspotMode();
    }

    function insertViewLink() {
        var view = readCurrentView();
        if (!view) {
            window.alert(config.strings.previewnotready);
            return;
        }

        var selection = window.getSelection();
        if (!selection.rangeCount || selection.isCollapsed) {
            window.alert(config.strings.selecttextfirst);
            return;
        }

        var range = selection.getRangeAt(0);
        var writeup = document.getElementById(config.writeupId);
        if (!writeup.contains(range.commonAncestorContainer)) {
            window.alert(config.strings.selectinsidewriteup);
            return;
        }

        var link = document.createElement('a');
        link.href = buildViewUrl(view);
        link.target = config.iframeName;

        try {
            range.surroundContents(link);
        } catch (e) {
            // Selection spans a partial/complex node structure surroundContents()
            // can't wrap directly (e.g. crossing element boundaries) - fall back to
            // extracting and re-wrapping the selected content instead.
            var contents = range.extractContents();
            link.appendChild(contents);
            range.insertNode(link);
        }

        selection.removeAllRanges();
    }

    // The <a> a mousedown most recently landed on inside the write-up box -
    // see removeViewLink() below for why this, and not the current
    // Selection/Range, is what it acts on.
    var lastClickedLink = null;

    /**
     * Unwraps the <a> most recently clicked inside the write-up box, leaving
     * its text behind - the only way to actually get rid of a view-link,
     * since insertViewLink() above can't replace one in place: selecting
     * text that's already (even partially) inside an existing <a> makes
     * range.surroundContents() throw (a Range can't wrap around part of one
     * element and not the rest), so it falls back to extractContents(),
     * which nests the new link inside the old one instead of replacing it -
     * the old, wrong href never actually goes away on its own.
     *
     * Deliberately keyed off the clicked DOM element (via the mousedown
     * listener below), not off window.getSelection() - a Range's
     * commonAncestorContainer can land above the actual <a> a teacher
     * clicked (e.g. an ordinary click-and-drag that starts inside a link
     * and drifts past its edge before release, which is easy to do without
     * noticing), which made an earlier Selection-walking version of this
     * unreliable at finding the link that was actually meant. Reading the
     * clicked element directly is unambiguous regardless of selection
     * shape, and for a nested/corrupted link (see the doc comment above)
     * correctly targets whichever layer was actually clicked on, so
     * clicking repeatedly peels one layer at a time.
     */
    function removeViewLink() {
        var writeup = document.getElementById(config.writeupId);
        var link = lastClickedLink;

        if (!link || !writeup.contains(link)) {
            window.alert(config.strings.clicklinkfirst);
            return;
        }

        var parent = link.parentNode;
        while (link.firstChild) {
            parent.insertBefore(link.firstChild, link);
        }
        parent.removeChild(link);
        lastClickedLink = null;
    }

    // The opening view chosen via setOpeningView() below - stored here rather
    // than applied to the live preview iframe's src, so setting it doesn't
    // reload/jump the preview the teacher is actively looking at. Read by
    // generateEmbed() when building the final iframe src.
    var openingView = null;

    /**
     * Reads the current pan/zoom position and remembers it as the position the
     * *generated embed* should open on - independent of, and in addition to, any
     * view-links inserted in the write-up text. Deliberately does not touch the
     * live preview iframe at all: it should feel the same as bookmarking a page
     * without navigating there, not like clicking a view-link.
     */
    function setOpeningView() {
        var view = readCurrentView();
        if (!view) {
            window.alert(config.strings.previewnotready);
            return;
        }

        openingView = view;

        var button = document.getElementById('omero-set-opening-btn');
        var original = button.textContent;
        button.textContent = config.strings.openingviewset;
        window.setTimeout(function() {
            button.textContent = original;
        }, 1500);
    }

    function generateEmbed() {
        var layout = currentLayout();

        // Every layout except image-only shows the write-up box (see
        // author.php's own $writeupextrastyle) - blank is very likely an
        // oversight rather than a deliberate choice in any of the other
        // three, whether it's meant to hold a full write-up (slide-left/
        // slide-right) or a short quiz-style question (text-below, see
        // #omero-hotspot-mode-wrap's own comment for why that layout exists
        // at all). .textContent, not .innerHTML - a contenteditable div
        // that's visually empty can still contain a stray empty <p></p> or
        // <br> depending on what the browser left behind after deleting
        // everything, which .innerHTML would count as "not blank" but a
        // reader would still see as empty.
        if (layout !== 'imageonly') {
            var writeupcheck = document.getElementById(config.writeupId);
            if (writeupcheck && writeupcheck.textContent.trim() === '') {
                window.alert(config.strings.needwriteuptext);
                writeupcheck.focus();
                return;
            }
        }

        // Escaped once here, reused everywhere below instead of the raw
        // values - see escapeHtmlAttr()'s own docblock for why every value
        // concatenated into this function's generated HTML goes through it,
        // not just the ones already known to be attacker-reachable today.
        var height = escapeHtmlAttr(currentHeight());
        var width = escapeHtmlAttr(currentWidth());
        var iframeName = escapeHtmlAttr(config.iframeName);

        // The live preview iframe's own src never changes (see setOpeningView()
        // above) - the opening view, if any, is applied here instead, at the
        // point the embed is actually generated.
        var iframeSrcUrl = new URL(openingView ? buildViewUrl(openingView) : config.baseProxyUrl, window.location.href);
        // Overlay checkboxes/colour swatches, re-read live rather than trusted
        // from config.baseProxyUrl - see currentWidth()'s own comment above
        // for why (this is the actual fix for that bug).
        var overlaySettings = currentOverlaySettings();
        Object.keys(overlaySettings).forEach(function(key) {
            iframeSrcUrl.searchParams.set(key, overlaySettings[key] ? '1' : '0');
        });
        // Not part of overlaySettings above - a dropdown, not a checkbox
        // (see author.php's own $hotspotmode comment).
        var hotspotmode = currentHotspotMode();
        iframeSrcUrl.searchParams.set('enablehotspot', hotspotmode === 'single' ? '1' : '0');
        iframeSrcUrl.searchParams.set('enablehotspotmulti', hotspotmode === 'multi' ? '1' : '0');
        var colours = currentColours();
        if (colours !== null) {
            iframeSrcUrl.searchParams.set('annotationcolours', colours.join(','));
        }
        // Lets proxy.php's inject_annotation_script() (see its own docblock)
        // know which embed placement this is - see getOrMintAnnotateId()'s
        // own comment for why this has to be stable across re-edits.
        iframeSrcUrl.searchParams.set('embedid', getOrMintAnnotateId());
        // Root-relative, not iframeSrcUrl.toString() (absolute) - same
        // reasoning as buildViewUrl() above: this gets baked directly into
        // the embed HTML the teacher pastes into a Page/Label, so it must
        // keep working if Moodle's own host/port ever changes later.
        var iframeSrc = iframeSrcUrl.pathname + iframeSrcUrl.search;

        // id (in addition to the existing name) gives the reset button in
        // the textbelow layout below a stable, unique target to reload -
        // important once a page/question has more than one embed.
        var iframeHtml = '<iframe id="' + iframeName + '" style="width: 100%; height: ' + height + ';" src="' +
            iframeSrc + '" name="' + iframeName + '"></iframe>';

        var inner;
        if (layout === 'imageonly') {
            inner = iframeHtml;
        } else if (layout === 'textbelow') {
            var textBelowWriteup = document.getElementById(config.writeupId);
            var resetBtnId = 'omero-reset-' + iframeName;
            // A plain onclick attribute gets silently stripped by TinyMCE's
            // own client-side schema on insert - confirmed live, it never
            // even reaches the server, regardless of how permissive Moodle's
            // own save/render sanitization is (mod_page and quiz question
            // text both skip HTMLPurifier entirely, but that's irrelevant if
            // the attribute is already gone before insertContent() returns).
            // A <script> tag survives instead (this site's own default
            // extended_valid_elements already includes script[*] - also
            // confirmed live), so the handler is attached that way, via
            // addEventListener rather than an inline attribute.
            // "Clear then restore src" - reassigning to the same value isn't
            // guaranteed to force a reload in every browser. Resets back to
            // iframeSrc, the same opening-view URL computed above - exactly
            // "the original position", regardless of how the viewer has since
            // panned/zoomed inside the live iviewer.
            var resetHtml = '<button type="button" id="' + resetBtnId + '" style="margin-top: 0.5rem;">'
                + config.strings.resetview + '</button>\n  <script>'
                + 'document.getElementById("' + resetBtnId + '").addEventListener("click", function() {'
                + 'var f = document.getElementById("' + config.iframeName + '"); var s = f.src; f.src = ""; f.src = s;'
                + '});</script>';
            inner = iframeHtml + '\n  ' + resetHtml +
                '\n  <div style="margin-top: 0.5rem; text-align: left;">' + textBelowWriteup.innerHTML + '</div>';
        } else {
            var writeup = document.getElementById(config.writeupId);
            var slideCell = '<td>' + iframeHtml + '</td>';
            var writeupCell = '<td style="width: 50%; text-align: left;">' + writeup.innerHTML + '</td>';
            var cells = layout === 'slideright' ? (writeupCell + '\n      ' + slideCell)
                : (slideCell + '\n      ' + writeupCell);

            inner = '<table style="width: 100%; height: ' + height + ';" cellspacing="1" cellpadding="10">\n' +
                '  <tbody>\n    <tr>\n      ' + cells + '\n    </tr>\n  </tbody>\n</table>';
        }

        // Wrapped in the same max-width the live preview itself is constrained
        // to (see author.php's #omero-preview-wrap and $width's own comment) -
        // otherwise the embed would render full-width wherever it's pasted,
        // same mismatch this whole constraint exists to avoid.
        // data-omero-embed marks this wrapper so tiny_omeroembed's own ui.js
        // can find and re-edit it later (see that file's handleAction()) -
        // only present on embeds generated from here on, not older ones.
        // data-omero-annotate-id is read back the same way (see that file's
        // readExistingEmbed()) so re-editing reuses getOrMintAnnotateId()'s
        // token instead of minting a fresh one.
        var html = '<div data-omero-embed="1" data-omero-annotate-id="' + getOrMintAnnotateId()
            + '" style="max-width: ' + width + ';">\n  ' + inner + '\n</div>';

        if (config.embedded) {
            // Handed off to the tiny_omeroembed TinyMCE plugin's modal (see
            // its amd/src/ui.js), which inserts this into the editor and
            // closes the modal - author.php's own copy-paste textarea isn't
            // rendered in embedded mode (see author.php), nothing more to do
            // here.
            window.parent.postMessage({type: 'omero-embed-html', html: html}, window.location.origin);
            return;
        }

        var output = document.getElementById('omero-output');
        output.value = html;
        document.getElementById('omero-output-wrap').style.display = 'block';
    }

    function copyEmbed() {
        var output = document.getElementById('omero-output');
        output.select();
        var button = document.getElementById('omero-copy-btn');
        var original = button.textContent;

        var done = function() {
            button.textContent = config.strings.copied;
            window.setTimeout(function() {
                button.textContent = original;
            }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(output.value).then(done, function() {
                document.execCommand('copy');
                done();
            });
        } else {
            document.execCommand('copy');
            done();
        }
    }

    document.getElementById('omero-insert-link-btn').addEventListener('click', insertViewLink);
    document.getElementById('omero-remove-link-btn').addEventListener('click', removeViewLink);
    // Tracks lastClickedLink for removeViewLink() - see its own doc comment
    // for why mousedown (fires at the start of a click *or* a drag-select,
    // before the selection itself has necessarily settled) rather than
    // click or the Selection API.
    document.getElementById(config.writeupId).addEventListener('mousedown', function(event) {
        lastClickedLink = event.target.closest ? event.target.closest('a') : null;
    });
    document.getElementById('omero-set-opening-btn').addEventListener('click', setOpeningView);
    document.getElementById('omero-generate-btn').addEventListener('click', generateEmbed);
    // Not rendered in embedded mode (see author.php) - generateEmbed()
    // posts the HTML to the parent editor instead of showing it here.
    var copyBtn = document.getElementById('omero-copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', copyEmbed);
    }

    // Layout radios live inside the setup form (so the initial choice survives
    // a bookmark/reload), but changing them here must never submit that form -
    // only "Load slide" does, since that's the only action that actually needs
    // a server round trip (a different image/subject/dataset). Applying the new
    // layout is handled entirely client-side, in place.
    var layoutRadios = document.querySelectorAll('input[name="layout"]');
    for (var i = 0; i < layoutRadios.length; i++) {
        layoutRadios[i].addEventListener('change', function(e) {
            applyLayout(e.target.value);
        });
    }

    // Every other overlay checkbox only ever affects the *final generated*
    // embed - generateEmbed() re-reads them live at insert time (see its
    // own comment), the live preview iframe itself never needs to know.
    // The hotspot mode is different: drawing the hidden region(s) has to
    // happen inside the preview iframe itself, which means proxy.php needs
    // to see authoring=1&enablehotspot(multi)=1&embedid=<this placement's
    // own token> on *that* iframe's own src before it will inject the
    // right authoring script - so changing this dropdown reloads the
    // preview into that mode. getOrMintAnnotateId() is reused as-is
    // (already idempotent/cached for the session) - the same token
    // generateEmbed() would mint anyway, just needed earlier than usual so
    // the region(s) get saved against the right placement from the start.
    //
    // A dropdown, not two checkboxes - see author.php's own $hotspotmode
    // comment for why. Confirmed as a real bug in the old two-checkbox
    // version: its own reload rebuilt the preview URL from
    // config.baseProxyUrl (the page's *original* load-time values), not
    // the teacher's *current* checkbox states - so toggling any other
    // overlay setting (e.g. "Hide OMERO top navigation bar") after
    // switching a hotspot mode on silently reverted to whatever that
    // setting was at page load, with no visible error. This version
    // rebuilds from currentOverlaySettings() instead, the same live-read
    // generateEmbed() already relies on, so it can never go stale that
    // way again.
    var hotspotModeSelect = document.querySelector('select[name="hotspotmode"]');
    var hotspotModeWrap = document.getElementById('omero-hotspot-mode-wrap');
    // proxy.php unconditionally forces enableannotations off on the actual
    // student-facing view whenever any hotspot mode is active (see its own
    // $hotspotoverridesstudentview comment - "no sensible way to want both
    // active on the same click at once") - greying this out (not just
    // unchecking it) keeps the form honest about that, instead of letting
    // a teacher believe it's still an active, independent choice.
    var annotationsCheckbox = document.querySelector('input[type="checkbox"][name="enableannotations"]');

    // "Active" goes through currentHotspotMode(), not hotspotModeSelect.value
    // directly - the dropdown itself lives inside author.php's
    // #omero-hotspot-mode-wrap, hidden (but not reset) by applyLayout()
    // below whenever the layout isn't text-below, so its raw .value can
    // still say 'single'/'multi' from before a teacher switched away. Going
    // through currentHotspotMode() is what actually enforces "only applies
    // to text-below" here too, not just in generateEmbed().
    function applyHotspotModeUI() {
        var active = (currentHotspotMode() !== '');
        if (annotationsCheckbox) {
            annotationsCheckbox.disabled = active;
            if (active) {
                annotationsCheckbox.checked = false;
            }
        }
    }

// Tracks what the live preview iframe's src was last actually built for,
    // seeded from the page's own initial state right after applyHotspotModeUI()'s
    // own first call below (the server already baked the correct
    // enablehotspot(multi) params into that initial src from $hotspotmode -
    // see author.php - so this starts in sync with it, not with a sentinel
    // that would force a spurious reload the first time anything at all
    // changes).
    var lastAppliedHotspotMode = null;

    /**
     * Reloads the live preview iframe to match whatever currentHotspotMode()
     * currently resolves to - '' (including "a mode is picked but the
     * layout isn't text-below") reloads it back to a plain read-only
     * preview; 'single'/'multi' reloads it into the drawing toolbar mode.
     * Called both when the dropdown itself changes and when *any* layout
     * change happens (applyLayout() calls this unconditionally, since
     * switching to/from text-below is exactly the case that needs it) - but
     * only actually reloads the iframe if the resolved mode genuinely
     * changed, via lastAppliedHotspotMode above. Without that check, every
     * layout switch would reload the preview even between two layouts that
     * have nothing to do with hotspot mode at all (e.g. slide-left <->
     * slide-right), losing whatever pan/zoom position the teacher had for
     * no reason.
     */
    function reloadPreviewForHotspotMode() {
        var mode = currentHotspotMode();
        if (mode === lastAppliedHotspotMode) {
            return;
        }
        lastAppliedHotspotMode = mode;

        var previewIframe = document.getElementById(config.iframeId);
        if (!previewIframe) {
            return;
        }
        var url = new URL(config.baseProxyUrl, window.location.href);
        var overlaySettings = currentOverlaySettings();
        Object.keys(overlaySettings).forEach(function(key) {
            url.searchParams.set(key, overlaySettings[key] ? '1' : '0');
        });
        url.searchParams.set('enablehotspot', mode === 'single' ? '1' : '0');
        url.searchParams.set('enablehotspotmulti', mode === 'multi' ? '1' : '0');
        url.searchParams.set('embedid', getOrMintAnnotateId());
        if (mode !== '') {
            // config.baseProxyUrl (the pristine, page-load base this
            // reload is rebuilt from every time) never has this set on
            // its own - the old two-checkbox version's own reload set
            // it explicitly for exactly this reason, and this rewrite
            // dropped it by mistake. Without it proxy.php has no way
            // to know it should inject the drawing toolbar/overlay at
            // all, so the preview looks like a plain read-only slide
            // with no way to mark a region.
            url.searchParams.set('authoring', '1');
        }
        previewIframe.src = url.toString();
    }

    if (hotspotModeSelect) {
        // Runs once on page load too - editing an existing hotspot embed
        // should start with annotations already greyed out, not just
        // after the teacher next touches the dropdown.
        applyHotspotModeUI();
        // Seeds lastAppliedHotspotMode (declared above reloadPreviewForHotspotMode())
        // to match what the server already baked into the preview iframe's
        // initial src, so the first real layout/dropdown change compares
        // against the true starting state instead of an empty sentinel.
        lastAppliedHotspotMode = currentHotspotMode();
        hotspotModeSelect.addEventListener('change', function() {
            applyHotspotModeUI();
            reloadPreviewForHotspotMode();
        });
    }

    if (config.embedded) {
        // If tiny_omeroembed's ui.js found an existing embed under/near the
        // cursor, it already pulled subject/images/dataset/layout/width/height
        // out of that embed's own DOM and passed them as ordinary GET params
        // (the setup form above is already pre-filled from those, same as any
        // bookmark/reload). The write-up text can't travel that way - it's
        // arbitrary rich HTML, not a handful of short values - so it comes
        // back separately over postMessage instead, once we signal we're
        // ready to receive it.
        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) {
                return;
            }
            if (!event.data || event.data.type !== 'omero-embed-existing-writeup') {
                return;
            }
            var writeup = document.getElementById(config.writeupId);
            if (writeup) {
                writeup.innerHTML = event.data.html;
            }
        });
        window.parent.postMessage({type: 'omero-embed-ready'}, window.location.origin);
    }
}());
