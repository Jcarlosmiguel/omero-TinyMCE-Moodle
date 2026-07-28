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

    /**
     * Reads the live iviewer iframe's current x/y (from .intensity-display's text)
     * and zm (from .ol-zoom-display's value). Returns null if either element isn't
     * found yet (iviewer still loading) or the text doesn't parse.
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

        var intensityEl = doc.querySelector('.intensity-display');
        var zoomEl = doc.querySelector('.ol-zoom-display');
        if (!intensityEl || !zoomEl) {
            return null;
        }

        var match = /X:\s*(\d+)\s*Y:\s*(\d+)/.exec(intensityEl.textContent || '');
        if (!match) {
            return null;
        }

        return {x: match[1], y: match[2], zm: zoomEl.value};
    }

    function buildViewUrl(view) {
        var url = new URL(config.baseProxyUrl, window.location.href);
        url.searchParams.set('x', view.x);
        url.searchParams.set('y', view.y);
        url.searchParams.set('zm', view.zm);
        return url.toString();
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
        var iframeWrap = document.getElementById('omero-iframe-wrap');

        if (layout === 'imageonly') {
            writeup.style.display = 'none';
            insertBtn.style.display = 'none';
            iframeWrap.style.flex = '1 1 100%';
            pane.style.flexDirection = 'row';
        } else {
            writeup.style.display = '';
            insertBtn.style.display = '';
            iframeWrap.style.flex = '1';
            iframeWrap.style.minWidth = '0';
            pane.style.flexDirection = layout === 'slideright' ? 'row-reverse' : 'row';
        }
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
        var iframe = document.getElementById(config.iframeId);
        var layout = currentLayout();

        // The live preview iframe's own src never changes (see setOpeningView()
        // above) - the opening view, if any, is applied here instead, at the
        // point the embed is actually generated.
        var iframeSrc = openingView ? buildViewUrl(openingView) : config.baseProxyUrl;

        var iframeHtml = '<iframe style="width: 100%; height: ' + iframe.style.height + ';" src="' +
            iframeSrc + '" name="' + config.iframeName + '"></iframe>';

        var inner;
        if (layout === 'imageonly') {
            inner = iframeHtml;
        } else {
            var writeup = document.getElementById(config.writeupId);
            var slideCell = '<td>' + iframeHtml + '</td>';
            var writeupCell = '<td style="width: 50%; text-align: left;">' + writeup.innerHTML + '</td>';
            var cells = layout === 'slideright' ? (writeupCell + '\n      ' + slideCell)
                : (slideCell + '\n      ' + writeupCell);

            inner = '<table style="width: 100%; height: ' + iframe.style.height + ';" cellspacing="1" cellpadding="10">\n' +
                '  <tbody>\n    <tr>\n      ' + cells + '\n    </tr>\n  </tbody>\n</table>';
        }

        // Wrapped in the same max-width the live preview itself is constrained
        // to (see author.php's #omero-preview-wrap and $width's own comment) -
        // otherwise the embed would render full-width wherever it's pasted,
        // same mismatch this whole constraint exists to avoid.
        var html = '<div style="max-width: ' + config.maxWidth + ';">\n  ' + inner + '\n</div>';

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
    document.getElementById('omero-set-opening-btn').addEventListener('click', setOpeningView);
    document.getElementById('omero-generate-btn').addEventListener('click', generateEmbed);
    document.getElementById('omero-copy-btn').addEventListener('click', copyEmbed);

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
}());
