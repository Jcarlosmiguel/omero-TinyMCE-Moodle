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
 * Click-to-answer hotspot feature: qtype_omerohotspot's own question-edit-
 * form authoring side. Same drag-to-draw drawing code as js/hotspot-
 * author.js (the standalone activity's own authoring script), but reports
 * the finished region to the parent *question edit form* via postMessage
 * instead of POSTing it to this plugin's own ajax.php - there is no
 * embedid, no server round-trip, and nothing persisted until the whole
 * question form is submitted (the geometry is just one more form field).
 *
 * Loaded only inside qtype_omerohotspot's edit-form preview iframe (see
 * proxy.php's inject_hotspot_edit_form_script(), gated on
 * local/omeroembed:hotspotauthor - same capability the standalone
 * activity's own authoring script requires).
 *
 * @module     local_omeroembed/hotspot-qtype-author
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var configEl = document.getElementById('omero-hotspot-qtype-author-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var HIT_RADIUS = 12;
    var HANDLE_OFFSET = 20; // px beyond the shape's own edge - identical convention to annotate.js/hotspot-author.js's own.

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var activeTool = null;
    var constrainShape = false;
    var pendingShape = null;
    var savedGeometry = null;
    var disabledInteractions = null;

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

    function pixelToImageCoord(px) {
        var coord = olmap.getCoordinateFromPixel(px);
        return [coord[0], -coord[1]];
    }

    /**
     * Rotates a click into the shape's own unrotated local frame - needed
     * for resize (tryStartResizeDrag()'s own use, in image-space) - the
     * rotate drag itself never needed this, it derives an angle from
     * atan2() directly instead.
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

    function screenRadii(centreX, centreY, rx, ry) {
        var centrePx = olmap.getPixelFromCoordinate([centreX, -centreY]);
        var edgePx = olmap.getPixelFromCoordinate([centreX + rx, -(centreY + ry)]);
        if (!centrePx || !edgePx) {
            return [0, 0];
        }
        return [Math.abs(edgePx[0] - centrePx[0]), Math.abs(edgePx[1] - centrePx[1])];
    }

    /**
     * Where the rotate handle sits on screen - identical formula to
     * annotate.js/hotspot-author.js's own handlePosition().
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
     * Traces an ellipse/rectangle outline into ctx's current path,
     * accounting for rotation - identical approach to annotate.js's own
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
     * to draw/hit-test the resize handles, including for an ellipse.
     *
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {Array} radii [screenRx, screenRy].
     * @param {number} rotation Radians.
     * @return {Array} four [screenX, screenY] points.
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

        var shape = pendingShape || savedGeometry;
        if (!shape) {
            return;
        }
        var radii = screenRadii(shape.x, shape.y, shape.rx, shape.ry);
        var px = olmap.getPixelFromCoordinate([shape.x, -shape.y]);
        if (!px) {
            return;
        }
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
        // new one is actively being drawn.
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

            // The 4 corner resize handles.
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

    /**
     * Posts the finished region up to the parent edit form - the one
     * genuine difference from js/hotspot-author.js, which POSTs to
     * ajax.php's hotspot_save instead. No confirmation round-trip needed:
     * the parent just writes this straight into its own hidden field.
     */
    function reportGeometry(geometry) {
        savedGeometry = geometry;
        redraw();
        window.parent.postMessage({type: 'omero-hotspot-geometry', geometry: geometry}, window.location.origin);
    }

    /**
     * Dragging a corner handle to resize - same interaction as
     * hotspot-author.js's own tryStartResizeDrag(), reporting via
     * reportGeometry() on release instead of an ajax.php POST.
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
            reportGeometry(savedGeometry);
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * Dragging the rotate handle - same interaction as hotspot-author.js's
     * own tryStartRotateDrag(), reporting via reportGeometry() on release
     * instead of an ajax.php POST (see this file's own docblock for why).
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

            savedGeometry.rotation = angleFor(upEvent);
            reportGeometry(savedGeometry);
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

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
            return {type: drawType, x: startCoord[0], y: startCoord[1], rx: rx, ry: ry, rotation: 0};
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
                redraw();
                return;
            }

            reportGeometry(finished);
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
            savedGeometry = null;
            redraw();
            window.parent.postMessage({type: 'omero-hotspot-geometry', geometry: null}, window.location.origin);
        });
        toolbar.appendChild(clearBtn);

        var statusEl = document.createElement('span');
        statusEl.id = 'omero-hotspot-status';
        statusEl.style.cssText = 'color:#2ecc71; font-size:0.8rem;';
        statusEl.textContent = config.strings.drawstatus;
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

        // Handshake with the parent edit form: this iframe has no way to
        // know an existing question's already-saved geometry on its own
        // (proxy.php serves the same generic OMERO viewer regardless of
        // which question is being edited) - so it announces readiness and
        // waits to be told, the same "ready, then receive" pattern
        // js/author.js's own writeup-text handoff already establishes for
        // an analogous problem.
        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) {
                return;
            }
            if (!event.data || event.data.type !== 'omero-hotspot-load-geometry') {
                return;
            }
            savedGeometry = event.data.geometry || null;
            redraw();
        });
        window.parent.postMessage({type: 'omero-hotspot-author-ready'}, window.location.origin);
    }

    init();
}());
