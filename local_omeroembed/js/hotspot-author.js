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
 * multi-shape/select machinery that file needs and this one doesn't: a
 * newly-drawn region simply replaces whatever was there before ("one
 * region per embed" - see hotspot_repository.php's own docblock), so
 * there is no select/edit-in-place case to build for the shape itself.
 *
 * Rotation is the one exception - reused directly from js/annotate.js
 * (handlePosition()/unrotate()-equivalent math, and the same drag-the-
 * handle gesture), since the server side (hotspot_repository.php's own
 * check_attempt(), and the same {type,x,y,rx,ry,rotation} shape ported
 * into both qtype plugins' lib.php) was already fully rotation-aware -
 * only the JS authoring side never exposed a way to set one. The handle
 * is shown whenever a region exists and no new draw is in progress -
 * there's no "selection" concept to gate it on, since there's only ever
 * the one region.
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
    var HANDLE_OFFSET = 20; // px beyond the shape's own edge - identical convention to annotate.js's own.

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var activeTool = null; // null | 'ellipse' | 'rectangle'
    var constrainShape = false; // touch-reachable equivalent of holding Shift - see annotate.js's own identical convention
    var pendingShape = null; // {type,x,y,rx,ry} while drag-drawing, else null
    var savedGeometry = null; // {type,x,y,rx,ry,rotation} the currently-persisted region, or null
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

    /**
     * Where the rotate handle sits on screen - identical formula to
     * js/annotate.js's own handlePosition().
     *
     * @param {Array} centrePx [screenX, screenY]
     * @param {Array} radii [screenRx, screenRy]
     * @param {number} rotation Radians.
     * @return {Array} [screenX, screenY] of the handle.
     */
    function handlePosition(centrePx, radii, rotation) {
        var distance = radii[1] + HANDLE_OFFSET;
        return [
            centrePx[0] + distance * Math.sin(rotation),
            centrePx[1] - distance * Math.cos(rotation),
        ];
    }

    /**
     * Rotates a click into the shape's own unrotated local frame -
     * identical formula to js/annotate.js's own unrotate() and
     * hotspot_repository.php's own PHP port of the same.
     *
     * @param {number} px
     * @param {number} py
     * @param {number} rotation Radians.
     * @return {Array} [px, py] in the shape's own local frame.
     */
    function unrotate(px, py, rotation) {
        var cos = Math.cos(rotation);
        var sin = Math.sin(rotation);
        return [px * cos + py * sin, -px * sin + py * cos];
    }

    /**
     * Traces an ellipse/rectangle outline into ctx's current path,
     * accounting for rotation - identical approach to js/annotate.js's own
     * traceShape().
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {string} type
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {Array} radii [screenRx, screenRy].
     * @param {number} rotation Radians.
     */
    /**
     * The 4 corners of a shape's own bounding box, rotated to match its
     * current orientation - used both to trace a rectangle's outline and
     * (below) to draw/hit-test the resize handles, including for an
     * ellipse, which has no literal corners of its own but still gets
     * resize handles at its bounding box's corners - the same convention
     * PowerPoint/Illustrator use for resizing a circle/ellipse by drag.
     *
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {Array} radii [screenRx, screenRy].
     * @param {number} rotation Radians.
     * @return {Array} four [screenX, screenY] points (top-left, top-right,
     *                 bottom-right, bottom-left in the shape's own
     *                 unrotated local frame).
     */
    function cornerPositions(cx, cy, radii, rotation) {
        return [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(function(sign) {
            var lx = sign[0] * radii[0];
            var ly = sign[1] * radii[1];
            return [
                cx + lx * Math.cos(rotation) - ly * Math.sin(rotation),
                cy + lx * Math.sin(rotation) + ly * Math.cos(rotation),
            ];
        });
    }

    function traceShape(ctx, type, cx, cy, radii, rotation) {
        if (type === TYPE_ELLIPSE) {
            ctx.ellipse(cx, cy, radii[0], radii[1], rotation, 0, 2 * Math.PI);
            return;
        }
        var corners = cornerPositions(cx, cy, radii, rotation);
        ctx.moveTo(corners[0][0], corners[0][1]);
        for (var i = 1; i < corners.length; i++) {
            ctx.lineTo(corners[i][0], corners[i][1]);
        }
        ctx.closePath();
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
        // Compounds the view's own current rotation on top of the shape's
        // stored rotation, same reasoning as annotate.js's own redraw() -
        // keeps the shape's on-screen orientation matching the image
        // regardless of view rotation, re-read fresh every redraw() since
        // this also fires continuously during a shift+drag of the view.
        var viewRotation = olmap.getView().getRotation();
        var rotation = (shape.rotation || 0) + viewRotation;

        ctx.save();
        ctx.strokeStyle = pendingShape ? '#2ecc71' : '#f5a623';
        ctx.lineWidth = pendingShape ? 2 : 3;
        if (!pendingShape) {
            ctx.setLineDash([8, 5]);
        }
        ctx.beginPath();
        traceShape(ctx, shape.type, px[0], px[1], radii, rotation);
        ctx.stroke();
        ctx.restore();

        // The rotate handle - only for the persisted region, not while a
        // new one is actively being drawn (there's nothing to rotate yet
        // mid-drag, and pendingShape has no .rotation of its own).
        if (!pendingShape && savedGeometry) {
            var handlePx = handlePosition(px, radii, rotation);
            ctx.save();
            ctx.beginPath();
            ctx.moveTo(px[0], px[1]);
            ctx.lineTo(handlePx[0], handlePx[1]);
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.beginPath();
            ctx.arc(handlePx[0], handlePx[1], 6, 0, 2 * Math.PI);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.strokeStyle = '#f5a623';
            ctx.lineWidth = 2;
            ctx.stroke();
            ctx.restore();

            // The 4 corner resize handles - small squares, distinct from
            // the round rotate handle, at the shape's own (rotated)
            // bounding-box corners. Square markers here follow the same
            // "shape suggests behaviour" convention as annotate.js's own
            // constrain-to-square icon.
            cornerPositions(px[0], px[1], radii, rotation).forEach(function(corner) {
                ctx.save();
                ctx.beginPath();
                ctx.rect(corner[0] - 5, corner[1] - 5, 10, 10);
                ctx.fillStyle = '#ffffff';
                ctx.fill();
                ctx.strokeStyle = '#f5a623';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.restore();
            });
        }
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
     * A corner resize handle, or the rotate handle, if either is there to
     * grab - checked unconditionally, before the draw-tool branch below,
     * same priority order as annotate.js's own onViewportPointerDown().
     * Corners first: they sit at the shape's own edge, the rotate handle
     * further out beyond it, so there's no real overlap between them to
     * disambiguate, but checking the closer one first is the more natural
     * order. See tryStartResizeDrag()/tryStartRotateDrag()'s own docblocks
     * for each drag itself.
     *
     * Drag-to-draw (the rest of this function, once this returns false):
     * press at the centre, drag out a radius, release - same gesture and
     * geometry formula as annotate.js's own
     * onViewportPointerDown()/computePending(), minus the "is this a
     * select instead" branch (there's nothing else on this canvas to
     * select).
     */
    function onViewportPointerDown(e) {
        if (savedGeometry && (tryStartResizeDrag(e) || tryStartRotateDrag(e))) {
            return;
        }

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
                // A freshly-drawn region always starts unrotated - rotation
                // is a separate, subsequent adjustment via the handle (see
                // tryStartRotateDrag()), not something this drag gesture
                // itself can express.
                rotation: 0,
            }, 'POST').then(function(result) {
                savedGeometry = result.geometry;
                redraw();
                showSavedMessage();
            });
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
    }

    /**
     * Dragging a corner handle to resize - always centre-anchored, not
     * opposite-corner-anchored: the same mental model the original
     * drag-to-draw gesture already established ("press at the centre, drag
     * out a radius"), just applied after the fact instead of only at
     * creation time. Which of the 4 corners was actually grabbed only
     * matters for this initial hit-test - once the drag starts, dragging
     * ANY corner produces the same result (the live cursor position,
     * converted to image coordinates and un-rotated into the shape's own
     * local frame, directly gives the new rx/ry - same
     * pixelToImageCoord()-based approach the original computePending()
     * already uses, just also un-rotating this time, since the original
     * draw always starts from rotation 0 and never needed to), so there's
     * no need to track which specific corner initiated it.
     *
     * @param {PointerEvent} e
     * @return {boolean} True if the press actually hit a corner.
     */
    function tryStartResizeDrag(e) {
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([savedGeometry.x, -savedGeometry.y]);
        var radii = screenRadii(savedGeometry.x, savedGeometry.y, savedGeometry.rx, savedGeometry.ry);
        var viewRotation = olmap.getView().getRotation();
        var rotation = (savedGeometry.rotation || 0) + viewRotation;
        var corners = cornerPositions(centrePx[0], centrePx[1], radii, rotation);

        var pressPx = [e.clientX - rect.left, e.clientY - rect.top];
        var hit = corners.some(function(corner) {
            var cdx = corner[0] - pressPx[0];
            var cdy = corner[1] - pressPx[1];
            return Math.sqrt(cdx * cdx + cdy * cdy) <= HIT_RADIUS;
        });
        if (!hit) {
            return false;
        }

        e.preventDefault();
        e.stopPropagation();
        setMapInteractionsEnabled(false);

        function computeResize(moveEvent) {
            var movePx = [moveEvent.clientX - rect.left, moveEvent.clientY - rect.top];
            var moveCoord = pixelToImageCoord(movePx);
            var dx = moveCoord[0] - savedGeometry.x;
            var dy = moveCoord[1] - savedGeometry.y;
            var local = unrotate(dx, dy, savedGeometry.rotation || 0);
            return {
                rx: Math.max(Math.abs(local[0]), 1),
                ry: Math.max(Math.abs(local[1]), 1),
            };
        }

        function onMove(moveEvent) {
            var resized = computeResize(moveEvent);
            savedGeometry.rx = resized.rx;
            savedGeometry.ry = resized.ry;
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);
            setMapInteractionsEnabled(true);

            var resized = computeResize(upEvent);
            savedGeometry.rx = resized.rx;
            savedGeometry.ry = resized.ry;
            redraw();

            ajax('hotspot_save', {
                type: savedGeometry.type,
                x: savedGeometry.x,
                y: savedGeometry.y,
                rx: resized.rx,
                ry: resized.ry,
                rotation: savedGeometry.rotation || 0,
            }, 'POST').then(function(result) {
                savedGeometry = result.geometry;
                redraw();
                showSavedMessage();
            });
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * Dragging the rotate handle - identical interaction to annotate.js's
     * own tryStartRotateDrag(), simplified: there's only ever the one
     * region here, so no selection state to check, just "does a region
     * exist" (already checked by the caller).
     *
     * @param {PointerEvent} e
     * @return {boolean} True if the press actually hit the handle.
     */
    function tryStartRotateDrag(e) {
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([savedGeometry.x, -savedGeometry.y]);
        var radii = screenRadii(savedGeometry.x, savedGeometry.y, savedGeometry.rx, savedGeometry.ry);
        var viewRotation = olmap.getView().getRotation();
        var rotation = (savedGeometry.rotation || 0) + viewRotation;
        var handlePx = handlePosition(centrePx, radii, rotation);

        var pressPx = [e.clientX - rect.left, e.clientY - rect.top];
        var hdx = handlePx[0] - pressPx[0];
        var hdy = handlePx[1] - pressPx[1];
        if (Math.sqrt(hdx * hdx + hdy * hdy) > HIT_RADIUS) {
            return false;
        }

        e.preventDefault();
        e.stopPropagation();
        setMapInteractionsEnabled(false);

        function angleFor(moveEvent) {
            var movePx = [moveEvent.clientX - rect.left, moveEvent.clientY - rect.top];
            return Math.atan2(movePx[0] - centrePx[0], -(movePx[1] - centrePx[1])) - viewRotation;
        }

        function onMove(moveEvent) {
            savedGeometry.rotation = angleFor(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);
            setMapInteractionsEnabled(true);

            var finalRotation = angleFor(upEvent);
            savedGeometry.rotation = finalRotation;
            redraw();

            ajax('hotspot_save', {
                type: savedGeometry.type,
                x: savedGeometry.x,
                y: savedGeometry.y,
                rx: savedGeometry.rx,
                ry: savedGeometry.ry,
                rotation: finalRotation,
            }, 'POST').then(function(result) {
                savedGeometry = result.geometry;
                redraw();
                showSavedMessage();
            });
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
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
