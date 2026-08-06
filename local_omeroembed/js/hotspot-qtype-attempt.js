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
 * Click-to-answer hotspot feature: qtype_omerohotspot's own student-
 * attempt side. Unlike js/hotspot-attempt.js (the standalone activity's
 * own version), this never calls ajax.php at all - there is no immediate
 * correct/incorrect verdict here, because in a real quiz that call is
 * Moodle's own question engine's job (grade_response(), called whenever
 * the surrounding question behaviour decides to grade - deferred,
 * immediate, or interactive-with-multiple-tries, entirely the teacher's
 * per-quiz choice, not this script's concern). This script only ever
 * reports *where* the student clicked, via postMessage, to
 * qtype_omerohotspot/question.js in the parent attempt page - which
 * writes it into this question's own hidden clickx/clicky fields,
 * submitted with the rest of the attempt form.
 *
 * Whether acting on a click is even appropriate right now (e.g. a review
 * screen, where a fresh click shouldn't overwrite a graded response) is
 * the *parent* module's decision, not this script's - it always reports a
 * click, and the parent simply chooses whether to listen.
 *
 * @module     local_omeroembed/hotspot-qtype-attempt
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
        document.querySelectorAll('.omero-hotspot-qtype-marker').forEach(function(el) {
            el.remove();
        });
        var el = document.createElement('div');
        el.className = 'omero-hotspot-qtype-marker';
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
        window.parent.postMessage({type: 'omero-hotspot-click', x: x, y: y}, window.location.origin);
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
