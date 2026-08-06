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
 * Teacher heatmap feature: periodically samples the viewer's own current
 * viewport centre + zoom and posts it to ajax.php's action=sample. Also
 * builds and shows the small on-slide "this is recorded" notice (confirmed
 * with the user - students should see this, not have it happen silently).
 *
 * The notice is built here in JS, appended to the viewport element,
 * *not* injected as static HTML by proxy.php - confirmed live that
 * iviewer's Aurelia app replaces <body>'s contents once it hydrates,
 * silently wiping a first attempt at doing exactly that. Same reason
 * annotate.js builds its own overlay canvas/toolbar in JS after polling
 * for the real viewer, rather than relying on anything injected as static
 * markup surviving.
 *
 * Injected only when tracking is active for this embed and the current
 * viewer isn't a teacher/manager (both re-checked server-side on every
 * request anyway - see ajax.php's action=sample).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var SAMPLE_INTERVAL_MS = 5000;
    var INIT_RETRY_MS = 300;

    var configEl = document.getElementById('omero-track-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    /**
     * Same Aurelia-component route already proven in author.js's
     * readCurrentView() and annotate.js's own viewer lookup - the real,
     * live OpenLayers Map instance is reachable via
     * document.querySelector('ol3-viewer').au.controller.viewModel.viewer.
     * Polled/retried rather than assumed ready, since this script loads in
     * <head>, well before iviewer's own app bundle (loaded later, in
     * <body>) finishes initialising.
     */
    function findViewer() {
        var el = document.querySelector('ol3-viewer');
        if (!el || !el.au || !el.au.controller) {
            return null;
        }
        return el.au.controller.viewModel.viewer;
    }

    /**
     * Same coordinate/zoom convention as author.js's readCurrentView():
     * OL's Y-up center vs image-pixel Y-down (center[1] negated), and
     * zoom_percent = round(1/resolution*100), lifted from iviewer's own
     * displayResolutionInPercent().
     */
    function readCurrentSample(viewer) {
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
            x: params.center[0],
            y: -params.center[1],
            zoompercent: (1 / params.resolution * 100)
        };
    }

    function postSample(sample) {
        var url = new URL(config.ajaxurl, window.location.href);
        url.searchParams.set('action', 'sample');
        url.searchParams.set('courseid', config.courseid);
        url.searchParams.set('embedid', config.embedid);

        var body = new URLSearchParams();
        body.set('sesskey', config.sesskey);
        body.set('x', sample.x);
        body.set('y', sample.y);
        body.set('zoompercent', sample.zoompercent);

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).catch(function() {
            // Best-effort - a dropped sample isn't worth surfacing to the
            // viewer or retrying, the next tick will just try again.
        });
    }

    function showNotice(viewportEl) {
        var notice = document.createElement('div');
        notice.id = 'omero-tracking-notice';
        notice.style.cssText = 'position:absolute; bottom:0.5rem; left:0.5rem; z-index:1000; '
            + 'background:rgba(0,0,0,0.65); color:#fff; font-size:0.75rem; padding:0.3rem 0.6rem; '
            + 'border-radius:4px; font-family:sans-serif; pointer-events:none;';
        notice.textContent = config.noticeText;
        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }
        viewportEl.appendChild(notice);
    }

    function init() {
        var viewer = findViewer();
        if (!viewer || !viewer.viewer_) {
            window.setTimeout(init, INIT_RETRY_MS);
            return;
        }

        var viewportEl = viewer.viewer_.getTargetElement();
        if (!viewportEl) {
            window.setTimeout(init, INIT_RETRY_MS);
            return;
        }

        showNotice(viewportEl);

        window.setInterval(function() {
            // Paused while the tab/window isn't visible, so switching away
            // doesn't keep recording a stale, unchanging viewport position.
            if (document.hidden) {
                return;
            }
            var sample = readCurrentSample(viewer);
            if (sample) {
                postSample(sample);
            }
        }, SAMPLE_INTERVAL_MS);
    }

    init();
}());
