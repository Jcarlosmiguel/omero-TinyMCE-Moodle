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
 * Multi-region click-to-answer hotspot feature: student side. A sibling of
 * js/hotspot-attempt.js, not a mode of it - the student's own interaction
 * is identical (read where they clicked, ask the server whether that's
 * correct, show the answer), only the server-side check differs (correct
 * against ANY one of several teacher-marked regions, see
 * classes/hotspot_multi_repository.php's own docblock). No region geometry
 * ever reaches this script or any script running in the student's
 * browser - hotspot_multi_repository::check_attempt() is the only thing
 * that ever compares a click against the stored regions, entirely
 * server-side, and returns nothing but a bare true/false.
 *
 * @module     local_omeroembed/hotspot-multi-attempt
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var configEl = document.getElementById('omero-hotspotmulti-attempt-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var olmap = null;
    var viewportEl = null;

    /** Same Aurelia-component route every other injected script here independently polls for. */
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

    function showFeedback(px, correct) {
        var el = document.createElement('div');
        el.textContent = correct ? config.strings.correct : config.strings.incorrect;
        el.style.cssText = 'position:absolute; left:' + px[0] + 'px; top:' + px[1] + 'px; '
            + 'transform:translate(-50%, -120%); z-index:1001; pointer-events:none; '
            + 'background:' + (correct ? 'rgba(46,204,113,0.9)' : 'rgba(231,76,60,0.9)') + '; '
            + 'color:#ffffff; padding:0.3rem 0.6rem; border-radius:4px; font-size:0.85rem; '
            + 'font-family:sans-serif; white-space:nowrap; transition:opacity 0.4s; opacity:1;';
        viewportEl.appendChild(el);
        window.setTimeout(function() {
            el.style.opacity = '0';
        }, 1400);
        window.setTimeout(function() {
            el.remove();
        }, 1800);
    }

    function onViewportClick(e) {
        var rect = viewportEl.getBoundingClientRect();
        var px = [e.clientX - rect.left, e.clientY - rect.top];
        var coord = olmap.getCoordinateFromPixel(px);
        var x = coord[0];
        var y = -coord[1];

        var url = new URL(config.ajaxurl, window.location.href);
        url.searchParams.set('action', 'hotspotmulti_attempt');
        url.searchParams.set('courseid', config.courseid);
        url.searchParams.set('embedid', config.embedid);

        var body = new URLSearchParams();
        body.set('sesskey', config.sesskey);
        body.set('x', x);
        body.set('y', y);

        fetch(url.toString(), {method: 'POST', body: body}).then(function(r) {
            return r.json();
        }).then(function(result) {
            showFeedback(px, !!result.correct);
        }).catch(function() {
            // Best-effort, same as track.js's own sample posting - a
            // dropped attempt just means no feedback shown this click,
            // nothing to retry automatically.
        });
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
