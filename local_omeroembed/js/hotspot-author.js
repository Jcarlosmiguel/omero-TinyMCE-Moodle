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
 * Click-to-answer hotspot feature: teacher-authoring side. Loaded only on
 * author.php's own live preview iframe, only once "Enable hotspot
 * question" is checked (see js/author.js's own change-listener and
 * proxy.php's inject_hotspot_author_script()) - never on the final
 * student-facing embed (see js/hotspot-attempt.js for that side).
 *
 * A small, deliberately separate sibling of js/annotate.js rather than a
 * mode bolted onto it - that file is a large, public, multi-shape,
 * student-facing module; this one is small, privileged, and only ever
 * handles a single secret shape. Conflating them would couple two features
 * with very different security postures for no real benefit (see this
 * plugin's own plan doc for the click-to-answer hotspot feature).
 *
 * Draws exactly one ellipse or rectangle by the same drag-to-draw gesture
 * js/annotate.js already established (press at the centre, drag out a
 * radius, release) - reused here rather than reinvented, minus the
 * multi-shape/select/rotate machinery that file needs and this one
 * doesn't: a hotspot region always has rotation 0, and a newly-drawn
 * region simply replaces whatever was there before ("one region per
 * embed" - see hotspot_repository.php's own docblock), so there is no
 * select/edit-in-place case to build.
 *
 * @module     local_omeroembed/hotspot-author
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var configEl = document.getElementById('omero-hotspot-author-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var HIT_RADIUS = 12; // Screen px - same "too small to be deliberate" threshold as annotate.js's own.

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var activeTool = null; // null | 'ellipse' | 'rectangle'
    var constrainShape = false; // touch-reachable equivalent of holding Shift - see annotate.js's own identical convention
    var pendingShape = null; // {type,x,y,rx,ry} while drag-drawing, else null
    var savedGeometry = null; // the currently-persisted region, or null
    var disabledInteractions = null;

    /**
     * Same Aurelia-component route annotate.js/track.js/heatmap-view.js
     * each independently poll for - iviewer does not expose OpenLayers as
     * a global.
     */
    function findViewer() {
        var el = document.querySelector('ol3-viewer');
        if (!el || !el.au || !el.au.controller) {
            return null;
        }
        var viewer = el.au.controller.viewModel.viewer;
        if (!viewer || !viewer.viewer_) {
            return null;
        }
        // The real OL map, not the wrapper - same unwrap annotate.js's own
        // findViewer() does, so every olmap.* call below is direct (no
        // further .viewer_ indirection needed anywhere else in this file).
        return viewer.viewer_;
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
        return fetch(url.toString()).then(function(r) {
            return r.json();
        });
    }

    function pixelToImageCoord(px) {
        var coord = olmap.getCoordinateFromPixel(px);
        return [coord[0], -coord[1]];
    }

    /** @return {number[]} [screenRx, screenRy] for an image-pixel radius at an image-pixel centre. */
    function screenRadii(centreX, centreY, rx, ry) {
        var centrePx = olmap.getPixelFromCoordinate([centreX, -centreY]);
        var edgePx = olmap.getPixelFromCoordinate([centreX + rx, -(centreY + ry)]);
        if (!centrePx || !edgePx) {
            return [0, 0];
        }
        return [Math.abs(edgePx[0] - centrePx[0]), Math.abs(edgePx[1] - centrePx[1])];
    }

    /**
     * Same "lock panning for as long as a shape tool is active" convention
     * as annotate.js's own setMapInteractionsEnabled()/refreshInteractionLock() -
     * simplified here since this module has only one lock reason (a draw
     * tool being active), not two.
     */
    function setMapInteractionsEnabled(enabled) {
        if (enabled) {
            if (disabledInteractions) {
                disabledInteractions.forEach(function(entry) {
                    entry.interaction.setActive(entry.wasActive);
                });
                disabledInteractions = null;
            }
            return;
        }
        if (disabledInteractions) {
            return;
        }
        disabledInteractions = olmap.getInteractions().getArray().map(function(interaction) {
            var wasActive = interaction.getActive();
            interaction.setActive(false);
            return {interaction: interaction, wasActive: wasActive};
        });
    }

    function redraw() {
        if (!overlayCanvas) {
            return;
        }
        var rect = viewportEl.getBoundingClientRect();
        if (overlayCanvas.width !== rect.width || overlayCanvas.height !== rect.height) {
            overlayCanvas.width = rect.width;
            overlayCanvas.height = rect.height;
        }
        var ctx = overlayCanvas.getContext('2d');
        ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        // The live in-progress drag takes priority over the last-saved
        // region while one is happening - once it's saved, savedGeometry
        // becomes the new reference outline and this goes back to null.
        var shape = pendingShape || savedGeometry;
        if (!shape) {
            return;
        }
        var radii = screenRadii(shape.x, shape.y, shape.rx, shape.ry);
        var px = olmap.getPixelFromCoordinate([shape.x, -shape.y]);
        if (!px) {
            return;
        }
        ctx.save();
        ctx.strokeStyle = pendingShape ? '#2ecc71' : '#f5a623';
        ctx.lineWidth = pendingShape ? 2 : 3;
        if (!pendingShape) {
            ctx.setLineDash([8, 5]);
        }
        ctx.beginPath();
        if (shape.type === TYPE_ELLIPSE) {
            ctx.ellipse(px[0], px[1], radii[0], radii[1], 0, 0, Math.PI * 2);
        } else {
            ctx.rect(px[0] - radii[0], px[1] - radii[1], radii[0] * 2, radii[1] * 2);
        }
        ctx.stroke();
        ctx.restore();
    }

    function showSavedMessage() {
        var el = document.getElementById('omero-hotspot-status');
        if (!el) {
            return;
        }
        el.textContent = config.strings.saved;
        el.style.opacity = '1';
        window.setTimeout(function() {
            el.style.opacity = '0';
        }, 1500);
    }

    /**
     * Drag-to-draw: press at the centre, drag out a radius, release - same
     * gesture and geometry formula as annotate.js's own
     * onViewportPointerDown()/computePending(), minus the "is this a
     * select instead" branch (there's nothing else on this canvas to
     * select) and minus rotation (always 0 - see this module's own
     * docblock for why).
     */
    function onViewportPointerDown(e) {
        if (activeTool !== TYPE_ELLIPSE && activeTool !== TYPE_RECTANGLE) {
            return;
        }
        var drawType = activeTool;
        var rect = viewportEl.getBoundingClientRect();
        var startPx = [e.clientX - rect.left, e.clientY - rect.top];
        e.preventDefault();
        e.stopPropagation();
        var startCoord = pixelToImageCoord(startPx);

        function computePending(moveEvent) {
            var movePx = [moveEvent.clientX - rect.left, moveEvent.clientY - rect.top];
            var moveCoord = pixelToImageCoord(movePx);
            var rx = Math.abs(moveCoord[0] - startCoord[0]);
            var ry = Math.abs(moveCoord[1] - startCoord[1]);
            if (moveEvent.shiftKey || constrainShape) {
                rx = ry = Math.max(rx, ry);
            }
            return {type: drawType, x: startCoord[0], y: startCoord[1], rx: rx, ry: ry};
        }

        function onMove(moveEvent) {
            pendingShape = computePending(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);

            var finished = pendingShape ? computePending(upEvent) : null;
            pendingShape = null;

            if (!finished) {
                redraw();
                return;
            }
            var radii = screenRadii(finished.x, finished.y, finished.rx, finished.ry);
            if (Math.max(radii[0], radii[1]) < HIT_RADIUS) {
                // Too small to be deliberate - discard rather than save a
                // near-invisible region nobody could ever click inside.
                redraw();
                return;
            }

            ajax('hotspot_save', {
                type: finished.type,
                x: finished.x,
                y: finished.y,
                rx: finished.rx,
                ry: finished.ry,
            }, 'POST').then(function(result) {
                savedGeometry = result.geometry;
                redraw();
                showSavedMessage();
            });
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
    }

    function buildToolbar() {
        var toolbar = document.createElement('div');
        toolbar.id = 'omero-hotspot-author-toolbar';
        toolbar.style.cssText = 'position:absolute; bottom:3rem; right:1rem; z-index:1000; '
            + 'background:rgba(0,0,0,0.7); padding:0.5rem; border-radius:4px; '
            + 'display:flex; align-items:center; gap:0.5rem; font-family:sans-serif;';

        var toolButtons = [];

        function makeToolButton(label, tool) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            btn.title = label;
            btn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
                + 'padding:0.3rem 0.6rem; font-size:0.8rem;';

            function update() {
                var isActive = activeTool === tool;
                btn.style.background = isActive ? '#2ecc71' : 'transparent';
                btn.style.color = isActive ? '#000000' : '#ffffff';
            }

            btn.addEventListener('click', function() {
                activeTool = (activeTool === tool) ? null : tool;
                setMapInteractionsEnabled(activeTool === null);
                toolButtons.forEach(function(b) {
                    b.update();
                });
            });

            btn.update = update;
            update();
            toolButtons.push(btn);
            return btn;
        }

        toolbar.appendChild(makeToolButton(config.strings.drawellipse, TYPE_ELLIPSE));
        toolbar.appendChild(makeToolButton(config.strings.drawrectangle, TYPE_RECTANGLE));

        // Touch-reachable equivalent of holding Shift while dragging - same
        // convention/reasoning as annotate.js's own constrainShape toggle.
        var constrainBtn = document.createElement('button');
        constrainBtn.type = 'button';
        constrainBtn.textContent = '○=□'; // circle=square, a plain-text stand-in icon
        constrainBtn.title = config.strings.constrainshape;
        constrainBtn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:transparent;';
        constrainBtn.addEventListener('click', function() {
            constrainShape = !constrainShape;
            constrainBtn.style.background = constrainShape ? '#2ecc71' : 'transparent';
            constrainBtn.style.color = constrainShape ? '#000000' : '#ffffff';
        });
        toolbar.appendChild(constrainBtn);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.textContent = config.strings.clear;
        clearBtn.title = config.strings.clear;
        clearBtn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:transparent;';
        clearBtn.addEventListener('click', function() {
            ajax('hotspot_clear', {}, 'POST').then(function() {
                savedGeometry = null;
                redraw();
            });
        });
        toolbar.appendChild(clearBtn);

        var statusEl = document.createElement('span');
        statusEl.id = 'omero-hotspot-status';
        statusEl.style.cssText = 'color:#2ecc71; font-size:0.8rem; opacity:0; transition:opacity 0.3s;';
        toolbar.appendChild(statusEl);

        viewportEl.parentNode.insertBefore(toolbar, viewportEl.nextSibling);
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
        overlayCanvas.id = 'omero-hotspot-author-overlay';
        overlayCanvas.style.cssText = 'position:absolute; top:0; left:0; '
            + 'width:100%; height:100%; pointer-events:none;';
        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }
        viewportEl.appendChild(overlayCanvas);

        viewportEl.addEventListener('pointerdown', onViewportPointerDown, true);
        olmap.on('postrender', redraw);

        buildToolbar();

        ajax('hotspot_get', {}, 'GET').then(function(result) {
            savedGeometry = result.geometry;
            redraw();
        });
    }

    init();
}());
