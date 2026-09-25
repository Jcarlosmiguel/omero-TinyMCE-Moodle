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
 * Multi-region click-to-answer hotspot feature: teacher-authoring side. A
 * sibling of js/hotspot-author.js, not a mode of it - that file draws
 * exactly one region ("one region per embed"); this one draws a SET of
 * equally-acceptable regions (e.g. several carcinogenic-looking cells on
 * the same slide - see this plugin's own plan doc), and a student's click
 * is correct against ANY one of them.
 *
 * Reuses hotspot-author.js's own drag-to-draw gesture (press at the
 * centre, drag out a radius, release) for drawing each individual region,
 * wrapped in the multi-shape UX js/annotate.js already established: the
 * Ellipse/Rectangle tool stays active across draws so a teacher can draw
 * region after region without re-selecting the tool, one overlay canvas
 * re-draws the whole set on every 'postrender', and clicking an existing
 * region selects it (a simplified, non-polygon version of annotate.js's
 * own annotationAtPixel()) for a "Delete selected" button - and, as of
 * this file's own rotation support, a rotate handle too, shown only for
 * whichever one region is currently selected rather than all of them at
 * once (a handle per region would be unreadable clutter the moment two
 * regions sit near each other).
 *
 * Persistence auto-saves on every add or delete (the whole current array
 * POSTed to ajax.php's hotspotmulti_save action) - same "never lose work
 * by navigating away" guarantee hotspot-author.js's own auto-save-on-
 * drag-release already gives the single-region feature, just extended to
 * a list. "Clear all" is a separate, still-immediate action
 * (hotspotmulti_clear), matching the single-region "Clear" button's own
 * immediacy for a fast, unambiguous "start over".
 *
 * @module     local_omeroembed/hotspot-multi-author
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var configEl = document.getElementById('omero-hotspotmulti-author-config');
    var config = configEl ? JSON.parse(configEl.textContent) : null;
    if (!config) {
        return;
    }

    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var HIT_RADIUS = 12; // Screen px - same "too small to be deliberate"/"still easy to click" threshold as hotspot-author.js/annotate.js's own.
    var HANDLE_OFFSET = 20; // px beyond the shape's own edge - identical convention to annotate.js/hotspot-author.js's own.

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var activeTool = null; // null | 'ellipse' | 'rectangle'
    var constrainShape = false; // touch-reachable equivalent of holding Shift - same convention as hotspot-author.js's own
    var pendingShape = null; // {type,x,y,rx,ry} while drag-drawing, else null
    var regions = []; // every saved region, in-memory mirror of the server's own array
    var selectedIndex = -1; // index into regions[], or -1 if nothing selected
    var disabledInteractions = null;
    var deleteBtn = null;

    /**
     * Same Aurelia-component route every other injected script here
     * independently polls for - iviewer does not expose OpenLayers as a
     * global.
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
     * as hotspot-author.js/annotate.js's own.
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

    /**
     * Traces an ellipse/rectangle outline into ctx's current path,
     * accounting for rotation - identical approach to annotate.js's own
     * traceShape() and hotspot-author.js's own copy of it.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {string} type
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {number} rx Screen radius, x axis.
     * @param {number} ry Screen radius, y axis.
     * @param {number} rotation Radians.
     */
    /**
     * The 4 corners of a shape's own bounding box, rotated to match its
     * current orientation - used both to trace a rectangle's outline and
     * (below) to draw/hit-test the resize handles, including for an
     * ellipse, which has no literal corners of its own but still gets
     * resize handles at its bounding box's corners.
     *
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {number} rx Screen radius, x axis.
     * @param {number} ry Screen radius, y axis.
     * @param {number} rotation Radians.
     * @return {Array} four [screenX, screenY] points.
     */
    function cornerPositions(cx, cy, rx, ry, rotation) {
        return [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(function(sign) {
            var lx = sign[0] * rx;
            var ly = sign[1] * ry;
            return [
                cx + lx * Math.cos(rotation) - ly * Math.sin(rotation),
                cy + lx * Math.sin(rotation) + ly * Math.cos(rotation),
            ];
        });
    }

    function traceShape(ctx, type, cx, cy, rx, ry, rotation) {
        if (type === TYPE_ELLIPSE) {
            ctx.ellipse(cx, cy, rx, ry, rotation, 0, 2 * Math.PI);
            return;
        }
        var corners = cornerPositions(cx, cy, rx, ry, rotation);
        ctx.moveTo(corners[0][0], corners[0][1]);
        for (var i = 1; i < corners.length; i++) {
            ctx.lineTo(corners[i][0], corners[i][1]);
        }
        ctx.closePath();
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
     * Rotates a click into a region's own unrotated local frame - identical
     * formula to annotate.js/hotspot-author.js's own unrotate() and
     * hotspot_multi_repository.php's own PHP port of the same.
     *
     * @param {number} px
     * @param {number} py
     * @param {number} rotation Radians.
     * @return {Array} [px, py] in the region's own local frame.
     */
    function unrotate(px, py, rotation) {
        var cos = Math.cos(rotation);
        var sin = Math.sin(rotation);
        return [px * cos + py * sin, -px * sin + py * cos];
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

        // Compounds the view's own current rotation on top of each region's
        // stored rotation - same reasoning as annotate.js/hotspot-author.js's
        // own redraw().
        var viewRotation = olmap.getView().getRotation();

        regions.forEach(function(region, index) {
            var radii = screenRadii(region.x, region.y, region.rx, region.ry);
            var px = olmap.getPixelFromCoordinate([region.x, -region.y]);
            if (!px) {
                return;
            }
            var rotation = (region.rotation || 0) + viewRotation;
            ctx.save();
            ctx.strokeStyle = (index === selectedIndex) ? '#3cb44b' : '#f5a623';
            ctx.lineWidth = (index === selectedIndex) ? 3 : 2.5;
            ctx.setLineDash([8, 5]);
            ctx.beginPath();
            traceShape(ctx, region.type, px[0], px[1], radii[0], radii[1], rotation);
            ctx.stroke();
            ctx.restore();

            // The rotate handle - only for the selected region, not every
            // one at once (see this file's own docblock for why).
            if (index === selectedIndex) {
                var handlePx = handlePosition(px, radii, rotation);
                ctx.save();
                ctx.setLineDash([]);
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
                ctx.strokeStyle = '#3cb44b';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.restore();

                // The 4 corner resize handles - only for the selected
                // region, same scoping as the rotate handle above.
                cornerPositions(px[0], px[1], radii[0], radii[1], rotation).forEach(function(corner) {
                    ctx.save();
                    ctx.beginPath();
                    ctx.rect(corner[0] - 5, corner[1] - 5, 10, 10);
                    ctx.fillStyle = '#ffffff';
                    ctx.fill();
                    ctx.strokeStyle = '#3cb44b';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                    ctx.restore();
                });
            }
        });

        // The live in-progress drag draws on top of every already-saved
        // region, same visual precedence hotspot-author.js's own redraw()
        // already gives a single pending shape over the saved reference
        // outline.
        if (pendingShape) {
            var pendingRadii = screenRadii(pendingShape.x, pendingShape.y, pendingShape.rx, pendingShape.ry);
            var pendingPx = olmap.getPixelFromCoordinate([pendingShape.x, -pendingShape.y]);
            if (pendingPx) {
                ctx.save();
                ctx.strokeStyle = '#2ecc71';
                ctx.lineWidth = 2;
                ctx.beginPath();
                traceShape(ctx, pendingShape.type, pendingPx[0], pendingPx[1], pendingRadii[0], pendingRadii[1], 0);
                ctx.stroke();
                ctx.restore();
            }
        }
    }

    /**
     * Simplified, non-rotating, ellipse/rectangle-only version of
     * annotate.js's own annotationAtPixel() - reverse-iterated so the
     * topmost (most recently drawn) region wins when two overlap, exactly
     * like that function's own convention.
     *
     * @param {number[]} px [screenX, screenY]
     * @return {number} index into regions[], or -1 if nothing hit.
     */
    function regionAtPixel(px) {
        for (var i = regions.length - 1; i >= 0; i--) {
            var region = regions[i];
            var centrePx = olmap.getPixelFromCoordinate([region.x, -region.y]);
            if (!centrePx) {
                continue;
            }
            var dx = centrePx[0] - px[0];
            var dy = centrePx[1] - px[1];
            var radii = screenRadii(region.x, region.y, region.rx, region.ry);
            var rx = Math.max(radii[0], HIT_RADIUS);
            var ry = Math.max(radii[1], HIT_RADIUS);
            // Rotate the click into the region's own unrotated local frame
            // first (same view-rotation compounding as redraw()), so this
            // still finds a region correctly once it's actually rotated -
            // without this, a rotated region would become hard or
            // impossible to select by clicking on what's now its visible
            // (rotated) outline, even though it's still selectable via its
            // old, no-longer-visible axis-aligned bounding box.
            var viewRotation = olmap.getView().getRotation();
            var local = unrotate(dx, dy, (region.rotation || 0) + viewRotation);
            if (region.type === TYPE_ELLIPSE) {
                var normalised = (local[0] * local[0]) / (rx * rx) + (local[1] * local[1]) / (ry * ry);
                if (normalised <= 1) {
                    return i;
                }
            } else if (Math.abs(local[0]) <= rx && Math.abs(local[1]) <= ry) {
                return i;
            }
        }
        return -1;
    }

    function updateDeleteButton() {
        if (!deleteBtn) {
            return;
        }
        deleteBtn.style.display = (selectedIndex >= 0) ? 'inline-block' : 'none';
    }

    function showSavedMessage() {
        var el = document.getElementById('omero-hotspotmulti-status');
        if (!el) {
            return;
        }
        el.textContent = config.strings.saved;
        el.style.opacity = '1';
        window.setTimeout(function() {
            el.style.opacity = '0';
        }, 1500);
    }

    /** Auto-saves the whole current array - see this module's own docblock for why. */
    function persistRegions() {
        ajax('hotspotmulti_save', {regions: JSON.stringify(regions)}, 'POST').then(function(result) {
            regions = result.geometry;
            redraw();
            showSavedMessage();
        });
    }

    /**
     * Click-to-select an existing region, exactly like annotate.js's own
     * separate onViewportClick() - deliberately a distinct 'click' listener
     * rather than folded into onViewportPointerDown() below, so a pan
     * gesture's own pointerdown (which fires at the drag's start point,
     * before OpenLayers knows it's a pan and not a click) never spuriously
     * toggles a selection. Only live when no draw tool is active - while
     * drawing, every pointer gesture belongs to onViewportPointerDown()
     * instead.
     */
    function onViewportClick(e) {
        if (activeTool === TYPE_ELLIPSE || activeTool === TYPE_RECTANGLE) {
            return;
        }
        var rect = viewportEl.getBoundingClientRect();
        var clickPx = [e.clientX - rect.left, e.clientY - rect.top];
        var hitIndex = regionAtPixel(clickPx);
        selectedIndex = (hitIndex === selectedIndex) ? -1 : hitIndex;
        updateDeleteButton();
        redraw();
    }

    /**
     * Dragging a corner handle to resize the selected region - identical
     * interaction to hotspot-author.js's own tryStartResizeDrag(), just
     * operating on regions[selectedIndex] and persisting via
     * persistRegions() (the whole-array save) instead of a single-shape
     * ajax.php POST.
     *
     * @param {PointerEvent} e
     * @return {boolean} True if the press actually hit a corner.
     */
    function tryStartResizeDrag(e) {
        var region = regions[selectedIndex];
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([region.x, -region.y]);
        var radii = screenRadii(region.x, region.y, region.rx, region.ry);
        var viewRotation = olmap.getView().getRotation();
        var rotation = (region.rotation || 0) + viewRotation;
        var corners = cornerPositions(centrePx[0], centrePx[1], radii[0], radii[1], rotation);

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
            var dx = moveCoord[0] - region.x;
            var dy = moveCoord[1] - region.y;
            var local = unrotate(dx, dy, region.rotation || 0);
            return {
                rx: Math.max(Math.abs(local[0]), 1),
                ry: Math.max(Math.abs(local[1]), 1),
            };
        }

        function onMove(moveEvent) {
            var resized = computeResize(moveEvent);
            region.rx = resized.rx;
            region.ry = resized.ry;
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);
            setMapInteractionsEnabled(true);

            var resized = computeResize(upEvent);
            region.rx = resized.rx;
            region.ry = resized.ry;
            redraw();
            persistRegions();
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * The selected region's rotate handle, if there's one to grab - checked
     * unconditionally, before the draw-tool branch below, same priority
     * order as annotate.js/hotspot-author.js's own onViewportPointerDown().
     *
     * @param {PointerEvent} e
     * @return {boolean} True if the press actually hit the handle.
     */
    function tryStartRotateDrag(e) {
        var region = regions[selectedIndex];
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([region.x, -region.y]);
        var radii = screenRadii(region.x, region.y, region.rx, region.ry);
        var viewRotation = olmap.getView().getRotation();
        var rotation = (region.rotation || 0) + viewRotation;
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
            region.rotation = angleFor(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);
            setMapInteractionsEnabled(true);

            region.rotation = angleFor(upEvent);
            persistRegions();
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * Drag-to-draw: press at the centre, drag out a radius, release - same
     * gesture and geometry formula as hotspot-author.js's own
     * onViewportPointerDown()/computePending().
     */
    function onViewportPointerDown(e) {
        if (selectedIndex >= 0 && (tryStartResizeDrag(e) || tryStartRotateDrag(e))) {
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
                // Too small to be deliberate - discard rather than add a
                // near-invisible region nobody could ever click inside.
                redraw();
                return;
            }

            regions.push(finished);
            persistRegions();
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
    }

    function buildToolbar() {
        var toolbar = document.createElement('div');
        toolbar.id = 'omero-hotspotmulti-author-toolbar';
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
                selectedIndex = -1;
                updateDeleteButton();
                setMapInteractionsEnabled(activeTool === null);
                toolButtons.forEach(function(b) {
                    b.update();
                });
                redraw();
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
        constrainBtn.textContent = '○=□';
        constrainBtn.title = config.strings.constrainshape;
        constrainBtn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:transparent;';
        constrainBtn.addEventListener('click', function() {
            constrainShape = !constrainShape;
            constrainBtn.style.background = constrainShape ? '#2ecc71' : 'transparent';
            constrainBtn.style.color = constrainShape ? '#000000' : '#ffffff';
        });
        toolbar.appendChild(constrainBtn);

        deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.textContent = config.strings.deleteregion;
        deleteBtn.title = config.strings.deleteregion;
        deleteBtn.style.cssText = 'cursor:pointer; border:1px solid #e74c3c; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:#e74c3c; display:none;';
        deleteBtn.addEventListener('click', function() {
            if (selectedIndex < 0) {
                return;
            }
            regions.splice(selectedIndex, 1);
            selectedIndex = -1;
            updateDeleteButton();
            persistRegions();
        });
        toolbar.appendChild(deleteBtn);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.textContent = config.strings.clear;
        clearBtn.title = config.strings.clear;
        clearBtn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:transparent;';
        clearBtn.addEventListener('click', function() {
            ajax('hotspotmulti_clear', {}, 'POST').then(function() {
                regions = [];
                selectedIndex = -1;
                updateDeleteButton();
                redraw();
            });
        });
        toolbar.appendChild(clearBtn);

        var statusEl = document.createElement('span');
        statusEl.id = 'omero-hotspotmulti-status';
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
        overlayCanvas.id = 'omero-hotspotmulti-author-overlay';
        overlayCanvas.style.cssText = 'position:absolute; top:0; left:0; '
            + 'width:100%; height:100%; pointer-events:none;';
        if (getComputedStyle(viewportEl).position === 'static') {
            viewportEl.style.position = 'relative';
        }
        viewportEl.appendChild(overlayCanvas);

        viewportEl.addEventListener('click', onViewportClick);
        viewportEl.addEventListener('pointerdown', onViewportPointerDown, true);
        olmap.on('postrender', redraw);

        buildToolbar();

        ajax('hotspotmulti_get', {}, 'GET').then(function(result) {
            regions = result.geometry || [];
            redraw();
        });
    }

    init();
}());
