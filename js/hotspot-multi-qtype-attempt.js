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
 * Multi-region click-to-answer hotspot feature: qtype_omerohotspotmulti's
 * own student-attempt side. A sibling of js/hotspot-qtype-attempt.js, not
 * a mode of it - the student's own interaction is identical (exactly one
 * click, reported via postMessage, no ajax.php call, no immediate verdict
 * here at all - grading happens later, server-side, whenever the
 * surrounding question behaviour decides to grade). Only the postMessage
 * `type` string differs, so a same-origin listener can never confuse this
 * qtype's payload with the single-region qtype's own.
 *
 * @module     local_omeroembed/hotspot-multi-qtype-attempt
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var olmap = null;
    var viewportEl = null;

    function findViewer() {
        var el = document.querySelector('ol3-viewer');
        if (!el || !el.au || !el.au.controller) {
            return null;
        }
        var viewer = el.au.controller.viewModel.viewer;
        if (!viewer || !viewer.viewer_) {
            return null;
        }
        return viewer.viewer_;
    }

    function showMarker(px) {
        document.querySelectorAll('.omero-hotspot-multi-qtype-marker').forEach(function(el) {
            el.remove();
        });
        var el = document.createElement('div');
        el.className = 'omero-hotspot-multi-qtype-marker';
        el.style.cssText = 'position:absolute; left:' + px[0] + 'px; top:' + px[1] + 'px; '
            + 'width:14px; height:14px; margin-left:-7px; margin-top:-7px; z-index:1001; '
            + 'border-radius:50%; border:2px solid #2e75b6; background:rgba(46,117,182,0.35); '
            + 'pointer-events:none;';
        viewportEl.appendChild(el);
    }

    function onViewportClick(e) {
        var rect = viewportEl.getBoundingClientRect();
        var px = [e.clientX - rect.left, e.clientY - rect.top];
        var coord = olmap.getCoordinateFromPixel(px);
        var x = coord[0];
        var y = -coord[1];

        showMarker(px);
        window.parent.postMessage({type: 'omero-hotspot-multi-click', x: x, y: y}, window.location.origin);
    }

    function init() {
        olmap = findViewer();
        if (!olmap) {
            window.setTimeout(init, 300);
            return;
        }

        viewportEl = olmap.getTargetElement();
        if (!viewportEl) {
            window.setTimeout(init, 300);
            return;
        }

        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }

        viewportEl.addEventListener('click', onViewportClick);
    }

    init();
}());
