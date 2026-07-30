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
 * Student point annotations, drawn directly on the live iviewer view. Loaded
 * only on the final student-facing embed (see proxy.php's
 * inject_annotation_script()), never the authoring tool's own preview.
 *
 * iviewer does not expose OpenLayers as a global (window.ol is undefined -
 * it's bundled/minified internally), so this deliberately does NOT try to
 * construct real OL layers/features. Instead it draws its own plain
 * <canvas> overlay, positioned over iviewer's own viewport, using the
 * REAL live map instance's own genuine getPixelFromCoordinate()/
 * getCoordinateFromPixel() methods for exact, rotation/zoom-aware
 * coordinate conversion (confirmed live, this session, via a round-trip
 * test) - we can freely call methods on the existing map/view instances,
 * we just can't `new` our own OL classes from outside the app's own
 * bundle. Points are drawn at a fixed pixel radius regardless of zoom -
 * Google Maps pin behaviour - which is simply what a constant-radius
 * circle in screen-space naturally does, no special-casing needed.
 *
 * @module     local_omeroembed/annotate
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var configEl = document.getElementById('omero-annotate-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var PIN_RADIUS = 8;
    var HIT_RADIUS = 12;
    var COLOURS = ['#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#000000'];

    var annotations = [];
    var selectedId = null;
    var currentColour = COLOURS[0];
    var placing = false;

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var mainCanvas = null;

    /**
     * iviewer's Aurelia app takes a moment to hydrate after this script
     * (loaded in <head>) starts running - poll rather than assume it's
     * ready, same defensive shape as author.js's readCurrentView().
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

    function findMainCanvas() {
        var candidates = viewportEl.querySelectorAll('canvas');
        var best = null;
        var bestArea = 0;
        candidates.forEach(function(c) {
            var area = c.width * c.height;
            if (area > bestArea) {
                bestArea = area;
                best = c;
            }
        });
        return best;
    }

    function ajax(action, params, method) {
        var url = new URL(config.ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        url.searchParams.set('courseid', config.courseid);
        url.searchParams.set('embedid', config.embedid);

        if (method === 'POST') {
            var body = new URLSearchParams(params || {});
            body.set('sesskey', config.sesskey);
            return fetch(url.toString(), {method: 'POST', body: body}).then(function(r) {
                return r.json();
            });
        }

        for (var key in (params || {})) {
            url.searchParams.set(key, params[key]);
        }
        return fetch(url.toString()).then(function(r) {
            return r.json();
        });
    }

    function resizeOverlay() {
        var rect = viewportEl.getBoundingClientRect();
        if (overlayCanvas.width !== rect.width || overlayCanvas.height !== rect.height) {
            overlayCanvas.width = rect.width;
            overlayCanvas.height = rect.height;
        }
    }

    function redraw() {
        resizeOverlay();
        var ctx = overlayCanvas.getContext('2d');
        ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        annotations.forEach(function(a) {
            var px = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!px) {
                return;
            }
            ctx.beginPath();
            ctx.arc(px[0], px[1], PIN_RADIUS, 0, 2 * Math.PI);
            ctx.fillStyle = a.colour;
            ctx.fill();
            if (a.id === selectedId) {
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#ffffff';
                ctx.stroke();
            }
        });
    }

    function annotationAtPixel(px) {
        for (var i = annotations.length - 1; i >= 0; i--) {
            var a = annotations[i];
            var apx = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!apx) {
                continue;
            }
            var dx = apx[0] - px[0];
            var dy = apx[1] - px[1];
            if (Math.sqrt(dx * dx + dy * dy) <= HIT_RADIUS) {
                return a;
            }
        }
        return null;
    }

    function onViewportClick(e) {
        var rect = viewportEl.getBoundingClientRect();
        var px = [e.clientX - rect.left, e.clientY - rect.top];

        var hit = annotationAtPixel(px);
        if (hit) {
            selectedId = (selectedId === hit.id) ? null : hit.id;
            updateDeleteButton();
            redraw();
            return;
        }

        if (!placing) {
            return;
        }

        var coord = olmap.getCoordinateFromPixel(px);
        ajax('create', {
            type: 'point',
            x: coord[0],
            y: -coord[1],
            colour: currentColour,
        }, 'POST').then(function(created) {
            annotations.push(created);
            redraw();
        });
    }

    function updateDeleteButton() {
        var btn = document.getElementById('omero-annotate-delete');
        btn.style.display = selectedId ? 'inline-block' : 'none';
    }

    function deleteSelected() {
        if (!selectedId) {
            return;
        }
        ajax('delete', {id: selectedId}, 'POST').then(function(result) {
            if (result.deleted) {
                annotations = annotations.filter(function(a) {
                    return a.id !== selectedId;
                });
                selectedId = null;
                updateDeleteButton();
                redraw();
            }
        });
    }

    function takeSnapshot() {
        var combined = document.createElement('canvas');
        combined.width = mainCanvas.width;
        combined.height = mainCanvas.height;
        var ctx = combined.getContext('2d');
        ctx.drawImage(mainCanvas, 0, 0);
        // Overlay canvas may be a different pixel size than the main
        // rendering canvas (CSS-scaled vs backing-store size) - draw it
        // scaled to match rather than assuming a 1:1 pixel match.
        ctx.drawImage(overlayCanvas, 0, 0, combined.width, combined.height);

        var link = document.createElement('a');
        link.href = combined.toDataURL('image/png');
        link.download = 'slide-annotations.png';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function buildToolbar() {
        var toolbar = document.createElement('div');
        toolbar.id = 'omero-annotate-toolbar';
        toolbar.style.cssText = 'position:absolute; bottom:1rem; right:1rem; z-index:1000; '
            + 'background:rgba(0,0,0,0.7); padding:0.5rem; border-radius:4px; '
            + 'display:flex; align-items:center; gap:0.5rem;';

        var placeBtn = document.createElement('button');
        placeBtn.type = 'button';
        placeBtn.textContent = config.strings.placepin;
        placeBtn.style.cssText = 'cursor:pointer;';
        placeBtn.addEventListener('click', function() {
            placing = !placing;
            placeBtn.style.fontWeight = placing ? 'bold' : 'normal';
            placeBtn.style.outline = placing ? '2px solid #fff' : 'none';
        });
        toolbar.appendChild(placeBtn);

        COLOURS.forEach(function(colour) {
            var swatch = document.createElement('button');
            swatch.type = 'button';
            swatch.title = colour;
            swatch.style.cssText = 'width:1.2rem; height:1.2rem; border-radius:50%; padding:0; '
                + 'cursor:pointer; background:' + colour + '; border:2px solid transparent;';
            if (colour === currentColour) {
                swatch.style.borderColor = '#ffffff';
            }
            swatch.addEventListener('click', function() {
                currentColour = colour;
                toolbar.querySelectorAll('button[title]').forEach(function(b) {
                    b.style.borderColor = 'transparent';
                });
                swatch.style.borderColor = '#ffffff';
            });
            toolbar.appendChild(swatch);
        });

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.id = 'omero-annotate-delete';
        deleteBtn.textContent = config.strings['delete'];
        deleteBtn.style.cssText = 'cursor:pointer; display:none;';
        deleteBtn.addEventListener('click', deleteSelected);
        toolbar.appendChild(deleteBtn);

        var snapshotBtn = document.createElement('button');
        snapshotBtn.type = 'button';
        snapshotBtn.textContent = config.strings.snapshot;
        snapshotBtn.style.cssText = 'cursor:pointer;';
        snapshotBtn.addEventListener('click', takeSnapshot);
        toolbar.appendChild(snapshotBtn);

        viewportEl.parentNode.insertBefore(toolbar, viewportEl.nextSibling);
    }

    function init() {
        olmap = findViewer();
        if (!olmap) {
            window.setTimeout(init, 300);
            return;
        }

        viewportEl = olmap.getTargetElement();
        mainCanvas = findMainCanvas();
        if (!viewportEl || !mainCanvas) {
            window.setTimeout(init, 300);
            return;
        }

        overlayCanvas = document.createElement('canvas');
        overlayCanvas.id = 'omero-annotate-overlay';
        overlayCanvas.style.cssText = 'position:absolute; top:0; left:0; '
            + 'width:100%; height:100%; pointer-events:none;';
        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }
        viewportEl.appendChild(overlayCanvas);

        // Clicks reach this listener even though overlayCanvas sits visually
        // on top - pointer-events:none makes it pass every pointer event
        // straight through, so this never interferes with iviewer's own
        // pan/zoom/drag handling (which uses mousedown/mousemove, not a
        // plain click).
        viewportEl.addEventListener('click', onViewportClick);

        olmap.on('postrender', redraw);

        buildToolbar();

        ajax('list', {}, 'GET').then(function(existing) {
            annotations = existing;
            redraw();
        });
    }

    init();
})();
