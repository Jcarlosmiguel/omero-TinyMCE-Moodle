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
 * Teacher heatmap feature: renders a density map of gathered viewport
 * samples over the slide, loaded via heatmap.php's iframe (proxy.php with
 * heatmap=1 - see inject_heatmap_view_script()). Same canvas-overlay-on-
 * postrender architecture as annotate.js (real live OL Map instance via
 * the Aurelia ol3-viewer component, getPixelFromCoordinate() for exact
 * coordinate<->pixel conversion), but drawing an accumulated density map
 * instead of discrete shapes, and with no drawing/pointer interaction of
 * its own - purely a read-only view.
 *
 * No external heatmap library - a small hand-rolled radial-gradient-blob +
 * colour-ramp implementation instead, consistent with this codebase's
 * existing no-CDN-dependency stance (see annotate.js's own hand-authored
 * inline SVG icons).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var BLOB_RADIUS = 40; // Screen px - the soft "footprint" of one sample.
    // Started at 60s, tightened after live use: a teacher watching this
    // *during* a live class (the actual intended use, not just a periodic
    // check-in) wants to see progress fairly quickly - and unlike
    // js/track.js's per-student sampling, only a handful of teachers ever
    // have this page open at once, so a shorter interval doesn't meaningfully
    // add server load.
    var REFRESH_INTERVAL_MS = 15000;

    var configEl = document.getElementById('omero-heatmap-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var blobCanvas = null; // Offscreen greyscale accumulation buffer, reused every redraw.
    var blobSprite = null; // Pre-rendered radial-gradient sprite, stamped once per sample.
    var samples = []; // [{x, y}] in real image-pixel coordinates, refreshed periodically - see refreshSamples().
    var lastUpdated = null; // Date of the last successful fetch, shown in the legend.
    var colourRamp = buildColourRamp();

    /**
     * Same Aurelia-component route already proven in author.js's
     * readCurrentView() and annotate.js's own findViewer() - reaches the
     * real, live OpenLayers Map instance. Polled/retried rather than
     * assumed ready, since this script loads in <head>, before iviewer's
     * own app bundle (loaded later, in <body>) finishes initialising.
     */
    function findViewer() {
        var viewerEl = document.querySelector('ol3-viewer');
        if (!viewerEl || !viewerEl.au || !viewerEl.au.controller) {
            return null;
        }
        var viewer = viewerEl.au.controller.viewModel.viewer;
        if (!viewer || !viewer.viewer_) {
            return null;
        }
        return viewer.viewer_;
    }

    function ajax(action, params) {
        var url = new URL(config.ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('courseid', config.courseid);
        url.searchParams.set('embedid', config.embedid);
        for (var key in (params || {})) {
            url.searchParams.set(key, params[key]);
        }
        // This exact URL gets re-fetched on a timer (see refreshSamples()) -
        // cache:'no-store' on top of ajax.php's own Cache-Control header,
        // so a repeated identical GET can never be silently served from the
        // browser's own HTTP cache instead of actually re-querying.
        return fetch(url.toString(), {cache: 'no-store'}).then(function(r) {
            return r.json();
        });
    }

    function resizeCanvases() {
        var rect = viewportEl.getBoundingClientRect();
        [overlayCanvas, blobCanvas].forEach(function(c) {
            if (c.width !== rect.width || c.height !== rect.height) {
                c.width = rect.width;
                c.height = rect.height;
            }
        });
    }

    /**
     * A soft radial-gradient sprite, drawn once and reused for every
     * sample - building a fresh CanvasGradient per point (potentially
     * thousands of times per redraw) is far slower than stamping a single
     * pre-rendered sprite with drawImage().
     */
    function buildBlobSprite() {
        var size = BLOB_RADIUS * 2;
        var sprite = document.createElement('canvas');
        sprite.width = size;
        sprite.height = size;
        var ctx = sprite.getContext('2d');
        var gradient = ctx.createRadialGradient(
            BLOB_RADIUS, BLOB_RADIUS, 0, BLOB_RADIUS, BLOB_RADIUS, BLOB_RADIUS
        );
        gradient.addColorStop(0, 'rgba(0,0,0,0.35)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, size, size);
        return sprite;
    }

    /**
     * Hand-rolled blue -> cyan -> green -> yellow -> red ramp - index
     * 0..255 (accumulated alpha from stamping blobSprite repeatedly) maps
     * to an RGB colour, "cold" (rarely looked at) to "hot" (frequently
     * looked at).
     */
    function buildColourRamp() {
        var stops = [[0, 0, 255], [0, 255, 255], [0, 255, 0], [255, 255, 0], [255, 0, 0]];
        var ramp = new Uint8ClampedArray(256 * 3);
        for (var i = 0; i < 256; i++) {
            var t = i / 255 * (stops.length - 1);
            var idx = Math.min(stops.length - 2, Math.floor(t));
            var frac = t - idx;
            var a = stops[idx];
            var b = stops[idx + 1];
            ramp[i * 3] = a[0] + (b[0] - a[0]) * frac;
            ramp[i * 3 + 1] = a[1] + (b[1] - a[1]) * frac;
            ramp[i * 3 + 2] = a[2] + (b[2] - a[2]) * frac;
        }
        return ramp;
    }

    function drawLegend(ctx) {
        var text = samples.length + ' sample' + (samples.length === 1 ? '' : 's');
        if (lastUpdated) {
            // Confirms the auto-refresh is actually running, rather than
            // leaving a teacher watching a live class wondering whether
            // the page has silently stalled.
            text += ' · updated ' + lastUpdated.toLocaleTimeString();
        }
        ctx.font = '12px sans-serif';
        var padding = 6;
        var width = ctx.measureText(text).width + padding * 2;
        ctx.fillStyle = 'rgba(0,0,0,0.65)';
        ctx.fillRect(8, 8, width, 22);
        ctx.fillStyle = '#ffffff';
        ctx.fillText(text, 8 + padding, 24);
    }

    function redraw() {
        if (!overlayCanvas) {
            return;
        }
        resizeCanvases();

        var overlayCtx = overlayCanvas.getContext('2d');
        overlayCtx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        if (!samples.length) {
            drawLegend(overlayCtx);
            return;
        }

        // Stamp every sample's screen-space blob onto the offscreen buffer,
        // accumulating overlapping blobs via 'lighter' compositing (alpha
        // channels add) - this is the actual "density" step.
        var blobCtx = blobCanvas.getContext('2d');
        blobCtx.clearRect(0, 0, blobCanvas.width, blobCanvas.height);
        blobCtx.globalCompositeOperation = 'lighter';
        samples.forEach(function(sample) {
            var px = olmap.getPixelFromCoordinate([sample.x, -sample.y]);
            if (!px) {
                return;
            }
            blobCtx.drawImage(blobSprite, px[0] - BLOB_RADIUS, px[1] - BLOB_RADIUS);
        });

        // Remap accumulated alpha through the colour ramp - a hand-rolled
        // equivalent of what dedicated heatmap libraries do, kept in plain
        // canvas pixel manipulation rather than an external dependency.
        var imageData = blobCtx.getImageData(0, 0, blobCanvas.width, blobCanvas.height);
        var data = imageData.data;
        for (var i = 0; i < data.length; i += 4) {
            var alpha = data[i + 3];
            if (alpha === 0) {
                continue;
            }
            var rampIdx = Math.min(255, alpha) * 3;
            data[i] = colourRamp[rampIdx];
            data[i + 1] = colourRamp[rampIdx + 1];
            data[i + 2] = colourRamp[rampIdx + 2];
            // Kept visibly translucent (never fully opaque) so the slide
            // underneath stays legible even over "hot" regions.
            data[i + 3] = Math.min(200, alpha + 60);
        }

        overlayCtx.putImageData(imageData, 0, 0);
        drawLegend(overlayCtx);
    }

    /**
     * Re-fetches the current sample set and redraws - called once on load
     * and then on a timer (see init()), so a teacher watching an in-class
     * activity sees the heatmap fill in without having to reload the page.
     */
    function refreshSamples() {
        ajax('heatmap', {}).then(function(data) {
            samples = data || [];
            lastUpdated = new Date();
            redraw();
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

        overlayCanvas = document.createElement('canvas');
        overlayCanvas.id = 'omero-heatmap-overlay';
        overlayCanvas.style.cssText = 'position:absolute; top:0; left:0; '
            + 'width:100%; height:100%; pointer-events:none;';
        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }
        viewportEl.appendChild(overlayCanvas);

        blobCanvas = document.createElement('canvas');
        blobSprite = buildBlobSprite();

        olmap.on('postrender', redraw);

        refreshSamples();
        // Guards against olmap.on('postrender', ...) above having been
        // wired *after* the map's own very first postrender already fired -
        // that would miss the one chance to compute correct pixel positions
        // once layout/tiles fully settle, and nothing would re-trigger a
        // redraw until the next timer tick (a whole REFRESH_INTERVAL_MS
        // later). One extra redraw shortly after load catches that race
        // cheaply, whether or not it was the actual cause of an earlier
        // "had to refresh once at the start" observation.
        window.setTimeout(redraw, 1000);

        window.setInterval(refreshSamples, REFRESH_INTERVAL_MS);

        // Browsers throttle (or fully suspend) setInterval timers in a
        // backgrounded/inactive tab - confirmed live: a teacher who tabs
        // away and back could otherwise be looking at data that's stale by
        // however long they were gone, waiting up to another full
        // REFRESH_INTERVAL_MS tick to catch up. Refreshing immediately the
        // moment the tab becomes visible again closes that gap regardless
        // of how long the timer itself was suspended for.
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshSamples();
            }
        });
    }

    init();
}());
