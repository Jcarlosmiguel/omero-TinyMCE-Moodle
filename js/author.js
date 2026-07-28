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

    /**
     * Reads the current pan/zoom position and bakes it into the live iframe's own
     * src, so it becomes the position the embed opens on - independent of, and in
     * addition to, any view-links inserted in the write-up text. Since
     * generateEmbed() below reads the iframe's *current* src attribute verbatim,
     * this is the only piece of state needed - no separate "opening view" tracked
     * on the side.
     */
    function setOpeningView() {
        var view = readCurrentView();
        if (!view) {
            window.alert(config.strings.previewnotready);
            return;
        }

        var iframe = document.getElementById(config.iframeId);
        iframe.src = buildViewUrl(view);

        var button = document.getElementById('omero-set-opening-btn');
        var original = button.textContent;
        button.textContent = config.strings.openingviewset;
        window.setTimeout(function() {
            button.textContent = original;
        }, 1500);
    }

    function generateEmbed() {
        var iframe = document.getElementById(config.iframeId);

        var iframeHtml = '<iframe style="width: 100%; height: ' + iframe.style.height + ';" src="' +
            iframe.getAttribute('src') + '" name="' + config.iframeName + '"></iframe>';

        var html;
        if (config.imageOnly) {
            html = iframeHtml;
        } else {
            var writeup = document.getElementById(config.writeupId);
            var slideCell = '<td>' + iframeHtml + '</td>';
            var writeupCell = '<td style="width: 50%; text-align: left;">' + writeup.innerHTML + '</td>';
            var cells = config.layout === 'slideright' ? (writeupCell + '\n      ' + slideCell)
                : (slideCell + '\n      ' + writeupCell);

            html = '<table style="width: 100%; height: ' + iframe.style.height + ';" cellspacing="1" cellpadding="10">\n' +
                '  <tbody>\n    <tr>\n      ' + cells + '\n    </tr>\n  </tbody>\n</table>';
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

    // "Insert view link" doesn't exist in image-only mode (no write-up text to
    // link from) - guard it rather than assume it's always on the page.
    var insertLinkBtn = document.getElementById('omero-insert-link-btn');
    if (insertLinkBtn) {
        insertLinkBtn.addEventListener('click', insertViewLink);
    }
    document.getElementById('omero-set-opening-btn').addEventListener('click', setOpeningView);
    document.getElementById('omero-generate-btn').addEventListener('click', generateEmbed);
    document.getElementById('omero-copy-btn').addEventListener('click', copyEmbed);
}());
