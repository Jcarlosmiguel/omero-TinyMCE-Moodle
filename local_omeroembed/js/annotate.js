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
 * Student point/ellipse/rectangle annotations, drawn directly on the live
 * iviewer view. Loaded only on the final student-facing embed (see
 * proxy.php's inject_annotation_script()), never the authoring tool's own
 * preview.
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
 * Ellipses and rectangles, unlike points, are stored with their extents in
 * real image-pixel units (geometry {x,y,rx,ry,rotation} - identical shape
 * for both, see isShapeType()), so their on-screen size grows and shrinks
 * with zoom like an actual measured region of the slide - the opposite of
 * a pin, and the reason they need their own drag-to-draw gesture (press/
 * move/release) rather than a single click. Free dragging sets rx/ry
 * independently; holding Shift while dragging constrains rx=ry to a
 * circle/square - the same convention as Illustrator/PowerPoint/Figma's
 * own shape tools, so a circle/square is just the equal-radii case rather
 * than its own shape/type. A selected ellipse/rectangle also gets a
 * draggable rotate handle (see tryStartRotateDrag()).
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
    var HANDLE_OFFSET = 20; // px beyond the shape's own edge, along its rotation axis
    // Resolved server-side by proxy.php's resolve_annotation_colours() -
    // the site default, or a teacher's own per-embed choice from
    // author.php - see that function's own docblock. The literal fallback
    // here only matters for an embed generated before this setting
    // existed and somehow missing config.colours entirely; every current
    // embed always has it.
    var COLOURS = (config.colours && config.colours.length) ? config.colours : ['#e6194B', '#3cb44b', '#4363d8', '#f58231'];
    var TYPE_POINT = 'point';
    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var TYPE_POLYGON = 'polygon';

    /**
     * Ellipse and rectangle share identical geometry {x,y,rx,ry,rotation}
     * and identical drag-to-draw/rotate-handle mechanics - they only
     * differ in how they're actually drawn and in their point-in-shape
     * hit-test, both handled locally wherever this is checked.
     *
     * @param {string} type
     * @return {boolean}
     */
    function isShapeType(type) {
        return type === TYPE_ELLIPSE || type === TYPE_RECTANGLE;
    }

    var annotations = [];
    var selectedId = null;
    var currentColour = COLOURS[0];
    var activeTool = null; // null | 'point' | 'ellipse' | 'rectangle' | 'polygon'
    // Touch/stylus devices (iPads, the majority of this plugin's actual
    // student devices) have no Shift key to hold - this toggle button is
    // the touch-reachable equivalent, checked identically alongside
    // moveEvent.shiftKey in onViewportPointerDown()'s computePending()
    // rather than replacing it, so a real keyboard's Shift key still
    // works exactly as before for anyone who has one.
    var constrainShape = false;
    var pendingShape = null; // {type, x, y, rx, ry} in image coords while drag-drawing, else null
    var pendingPolygon = null; // {points: [[imgX,imgY], ...]} while multi-click-drawing, else null
    var polygonCursorPx = null; // [screenX, screenY] - live cursor position, for the rubber-band preview segment
    var disabledInteractions = null; // [{interaction, wasActive}] while a lock reason applies, else null

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var mainCanvas = null;

    /**
     * @param {Array} centre [imageX, imageY]
     * @param {number} rx In real image-pixel units.
     * @param {number} ry In real image-pixel units.
     * @return {Array} [screenRx, screenRy] in *current* screen pixels - uses
     *                  the map's own exact pixel projection (via two points
     *                  offset from centre, one per axis) rather than
     *                  deriving it from resolution by hand, so it stays
     *                  correct under rotation too, not just plain zoom.
     */
    function screenRadii(centre, rx, ry) {
        var centrePx = olmap.getPixelFromCoordinate(centre);
        var xEdgePx = olmap.getPixelFromCoordinate([centre[0] + rx, centre[1]]);
        var yEdgePx = olmap.getPixelFromCoordinate([centre[0], centre[1] + ry]);
        if (!centrePx || !xEdgePx || !yEdgePx) {
            return [0, 0];
        }
        var dxx = xEdgePx[0] - centrePx[0];
        var dxy = xEdgePx[1] - centrePx[1];
        var dyx = yEdgePx[0] - centrePx[0];
        var dyy = yEdgePx[1] - centrePx[1];
        return [Math.sqrt(dxx * dxx + dxy * dxy), Math.sqrt(dyx * dyx + dyy * dyy)];
    }

    /**
     * Where the rotate handle sits on screen for a selected ellipse/
     * rectangle: a point HANDLE_OFFSET beyond the top of the shape's own
     * (unrotated) local frame, then rotated to its current angle -
     * matching PowerPoint/Illustrator's own handle-above-the-shape
     * convention. All in screen pixels; rotation here is a plain canvas-
     * style angle (radians, clockwise from the local "up" direction).
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
     * @param {number} px The click x, relative to a shape's own centre.
     * @param {number} py The click y, relative to a shape's own centre.
     * @param {number} rotation Radians.
     * @return {Array} [px, py] rotated into the shape's own unrotated
     *                 local frame, so the plain axis-aligned point-in-
     *                 shape formula still applies regardless of rotation.
     */
    function unrotate(px, py, rotation) {
        var cos = Math.cos(rotation);
        var sin = Math.sin(rotation);
        return [px * cos + py * sin, -px * sin + py * cos];
    }

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

    /**
     * Traces the outline of an ellipse or rectangle into ctx's current
     * path (caller does beginPath()/stroke()) - the one place drawing and
     * hit-testing would otherwise diverge, so both shapes render with
     * exactly the geometry their own point-in-shape test agrees on.
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {string} type TYPE_ELLIPSE or TYPE_RECTANGLE.
     * @param {number} cx Screen x of the shape's centre.
     * @param {number} cy Screen y of the shape's centre.
     * @param {Array} radii [screenRx, screenRy].
     * @param {number} rotation Radians.
     */
    function traceShape(ctx, type, cx, cy, radii, rotation) {
        if (type === TYPE_ELLIPSE) {
            ctx.ellipse(cx, cy, radii[0], radii[1], rotation, 0, 2 * Math.PI);
            return;
        }
        // Rectangle: four corners in the shape's own local (unrotated)
        // frame, each rotated back out to screen space and connected -
        // ctx.rotate() would work too, but doing it by hand here keeps
        // this in the same "rotate points, not the canvas" style as
        // unrotate()/handlePosition(), and avoids having to save/restore
        // canvas state around it.
        var corners = [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(function(sign) {
            var lx = sign[0] * radii[0];
            var ly = sign[1] * radii[1];
            return [
                cx + lx * Math.cos(rotation) - ly * Math.sin(rotation),
                cy + lx * Math.sin(rotation) + ly * Math.cos(rotation),
            ];
        });
        ctx.moveTo(corners[0][0], corners[0][1]);
        for (var i = 1; i < corners.length; i++) {
            ctx.lineTo(corners[i][0], corners[i][1]);
        }
        ctx.closePath();
    }

    function redraw() {
        resizeOverlay();
        var ctx = overlayCanvas.getContext('2d');
        ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        // geometry.rotation is stored relative to the image's own axes, not
        // the screen - shift+drag rotates the *view*, not the image, so a
        // shape drawn using only its own stored rotation would visually
        // stop tracking the image the moment the view is rotated (reported
        // directly by the user). Compounding the view's current rotation
        // on top, every redraw, keeps every shape's on-screen orientation
        // matching the image regardless of view rotation - re-read fresh
        // each call since redraw() already fires on every 'postrender',
        // including continuously *during* a shift+drag.
        var viewRotation = olmap.getView().getRotation();

        var selectedPx = null;
        var selectedTopY = null;
        annotations.forEach(function(a) {
            // Polygon geometry has no single x/y (see TYPE_POLYGON's own
            // comment) - handled as its own branch, before the generic
            // per-shape px below (which would otherwise compute
            // getPixelFromCoordinate() from undefined a.geometry.x/y for a
            // polygon and not be reliably caught by a falsy check).
            if (a.type === TYPE_POLYGON) {
                var polyPoints = a.geometry.points.map(function(point) {
                    return olmap.getPixelFromCoordinate([point[0], -point[1]]);
                });
                if (polyPoints.some(function(p) { return !p; })) {
                    return;
                }
                ctx.beginPath();
                ctx.moveTo(polyPoints[0][0], polyPoints[0][1]);
                for (var pi = 1; pi < polyPoints.length; pi++) {
                    ctx.lineTo(polyPoints[pi][0], polyPoints[pi][1]);
                }
                ctx.closePath();
                ctx.lineWidth = (a.id === selectedId) ? 3 : 2;
                ctx.strokeStyle = a.colour;
                ctx.stroke();
                if (a.id === selectedId) {
                    // Topmost vertex, for the label position - consistent
                    // with every other shape's "selectedTopY" meaning
                    // "just above the shape".
                    selectedPx = polyPoints[0];
                    selectedTopY = Math.min.apply(null, polyPoints.map(function(p) {
                        return p[1];
                    }));
                }
                return;
            }

            var px = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!px) {
                return;
            }

            if (isShapeType(a.type)) {
                var rotation = (a.geometry.rotation || 0) + viewRotation;
                var radii = screenRadii([a.geometry.x, -a.geometry.y], a.geometry.rx, a.geometry.ry);
                ctx.beginPath();
                traceShape(ctx, a.type, px[0], px[1], radii, rotation);
                ctx.lineWidth = (a.id === selectedId) ? 3 : 2;
                ctx.strokeStyle = a.colour;
                ctx.stroke();
                if (a.id === selectedId) {
                    selectedPx = px;
                    selectedTopY = px[1] - radii[1];

                    var handlePx = handlePosition(px, radii, rotation);
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
                    ctx.strokeStyle = a.colour;
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }
            } else {
                ctx.beginPath();
                ctx.arc(px[0], px[1], PIN_RADIUS, 0, 2 * Math.PI);
                ctx.fillStyle = a.colour;
                ctx.fill();
                if (a.id === selectedId) {
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#ffffff';
                    ctx.stroke();
                    selectedPx = px;
                    selectedTopY = px[1] - PIN_RADIUS;
                }
            }
        });

        if (pendingShape) {
            var centrePx = olmap.getPixelFromCoordinate([pendingShape.x, -pendingShape.y]);
            var pendingRadii = screenRadii([pendingShape.x, -pendingShape.y], pendingShape.rx, pendingShape.ry);
            if (centrePx) {
                ctx.beginPath();
                traceShape(ctx, pendingShape.type, centrePx[0], centrePx[1], pendingRadii, 0);
                ctx.setLineDash([4, 4]);
                ctx.lineWidth = 2;
                ctx.strokeStyle = currentColour;
                ctx.stroke();
                ctx.setLineDash([]);
            }
        }

        if (pendingPolygon) {
            var placedPx = pendingPolygon.points.map(function(point) {
                return olmap.getPixelFromCoordinate([point[0], -point[1]]);
            });
            if (placedPx.length && placedPx.every(function(p) { return !!p; })) {
                ctx.beginPath();
                ctx.moveTo(placedPx[0][0], placedPx[0][1]);
                for (var ppi = 1; ppi < placedPx.length; ppi++) {
                    ctx.lineTo(placedPx[ppi][0], placedPx[ppi][1]);
                }
                // Rubber-band segment to the live cursor position - not
                // closed back to the start, so it's visually distinct from
                // a finished polygon while still being drawn.
                if (polygonCursorPx) {
                    ctx.lineTo(polygonCursorPx[0], polygonCursorPx[1]);
                }
                ctx.setLineDash([4, 4]);
                ctx.lineWidth = 2;
                ctx.strokeStyle = currentColour;
                ctx.stroke();
                ctx.setLineDash([]);

                // Each already-placed vertex, so it's clear where a click
                // will land to close the loop (especially the first one,
                // highlighted the same way the rotate handle is, since
                // clicking back on it is what finishes the polygon).
                placedPx.forEach(function(p, i) {
                    ctx.beginPath();
                    ctx.arc(p[0], p[1], i === 0 ? 6 : 4, 0, 2 * Math.PI);
                    ctx.fillStyle = i === 0 ? '#ffffff' : currentColour;
                    ctx.fill();
                    if (i === 0) {
                        ctx.strokeStyle = currentColour;
                        ctx.lineWidth = 2;
                        ctx.stroke();
                    }
                });
            }
        }

        updateLabel(selectedPx, selectedTopY);
    }

    /**
     * Positions the label tooltip just above the selected annotation's
     * current screen position - called every redraw() (i.e. every
     * postrender), so it tracks pan/zoom exactly like the annotation
     * itself rather than only being placed once at selection time.
     *
     * @param {Array|null} px [screenX, screenY] of the selected annotation's
     *                        centre, or null if nothing's selected/it has
     *                        no label to show.
     * @param {number|null} topY The y pixel of the annotation's top edge
     *                           (accounts for a circle's radius, unlike a
     *                           pin's fixed PIN_RADIUS).
     */
    function updateLabel(px, topY) {
        var label = document.getElementById('omero-annotate-label');
        var selected = annotations.find(function(a) {
            return a.id === selectedId;
        });
        if (!px || !selected || !selected.label) {
            label.style.display = 'none';
            return;
        }
        label.textContent = selected.label;
        label.style.display = 'block';
        label.style.left = px[0] + 'px';
        label.style.top = (topY - 8) + 'px';
    }

    /**
     * Standard ray-casting (odd-even rule) point-in-polygon test, against
     * already-projected screen points - counts how many polygon edges a
     * horizontal ray from px crosses; odd means inside. Self-contained,
     * no library needed for something this well-established.
     *
     * @param {Array} px [screenX, screenY]
     * @param {Array} points [[screenX, screenY], ...]
     * @return {boolean}
     */
    function pointInPolygon(px, points) {
        var inside = false;
        for (var i = 0, j = points.length - 1; i < points.length; j = i++) {
            var xi = points[i][0];
            var yi = points[i][1];
            var xj = points[j][0];
            var yj = points[j][1];
            var intersects = ((yi > px[1]) !== (yj > px[1])) &&
                (px[0] < (xj - xi) * (px[1] - yi) / (yj - yi) + xi);
            if (intersects) {
                inside = !inside;
            }
        }
        return inside;
    }

    function annotationAtPixel(px) {
        for (var i = annotations.length - 1; i >= 0; i--) {
            var a = annotations[i];

            // Polygon geometry has no single x/y - see TYPE_POLYGON's own
            // comment and redraw()'s matching branch for why this has to
            // be handled separately, before the generic apx below.
            if (a.type === TYPE_POLYGON) {
                var polyPx = a.geometry.points.map(function(point) {
                    return olmap.getPixelFromCoordinate([point[0], -point[1]]);
                });
                if (polyPx.some(function(p) { return !p; })) {
                    continue;
                }
                if (pointInPolygon(px, polyPx)) {
                    return a;
                }
                continue;
            }

            var apx = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!apx) {
                continue;
            }
            var dx = apx[0] - px[0];
            var dy = apx[1] - px[1];
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (isShapeType(a.type)) {
                var radii = screenRadii([a.geometry.x, -a.geometry.y], a.geometry.rx, a.geometry.ry);
                // Rotate the click into the shape's own unrotated local
                // frame first, so the plain axis-aligned formulas below
                // still apply regardless of rotation.
                var local = unrotate(dx, dy, a.geometry.rotation || 0);
                // Clamp each axis to at least HIT_RADIUS so a thin/tiny
                // shape is still easy to click, same reasoning as a
                // circle's own Math.max() before it - a proper point-in-
                // shape test rather than a plain circular one, since rx
                // and ry can now genuinely differ.
                var rx = Math.max(radii[0], HIT_RADIUS);
                var ry = Math.max(radii[1], HIT_RADIUS);
                if (a.type === TYPE_ELLIPSE) {
                    var normalised = (local[0] * local[0]) / (rx * rx) + (local[1] * local[1]) / (ry * ry);
                    if (normalised <= 1) {
                        return a;
                    }
                } else if (Math.abs(local[0]) <= rx && Math.abs(local[1]) <= ry) {
                    return a;
                }
            } else if (dist <= HIT_RADIUS) {
                return a;
            }
        }
        return null;
    }

    /**
     * Places (or closes) a polygon vertex - point/ellipse/rectangle each
     * have a drag to distinguish "select an existing shape" from "start
     * drawing", but polygon is click-only, so every click while this tool
     * is active is deliberately treated as placing a vertex, even one
     * that happens to land on top of a different existing annotation -
     * there's no separate gesture available to mean "select instead" the
     * way a drag does for the other shapes.
     *
     * @param {Array} px [screenX, screenY]
     */
    function handlePolygonClick(px) {
        if (!pendingPolygon) {
            pendingPolygon = {points: [pixelToImageCoord(px)]};
            redraw();
            updatePolygonCancelButton();
            return;
        }

        // Clicking back near the first vertex closes the loop - the
        // standard convention this kind of tool uses elsewhere (e.g.
        // GeoJSON.io, Google My Maps), and simpler/more robust than trying
        // to disambiguate a genuine double-click from two separate
        // single-click vertex placements.
        if (pendingPolygon.points.length >= 3) {
            var firstPoint = pendingPolygon.points[0];
            var firstPx = olmap.getPixelFromCoordinate([firstPoint[0], -firstPoint[1]]);
            if (firstPx) {
                var dx = firstPx[0] - px[0];
                var dy = firstPx[1] - px[1];
                if (Math.sqrt(dx * dx + dy * dy) <= HIT_RADIUS) {
                    finishPolygon();
                    return;
                }
            }
        }

        pendingPolygon.points.push(pixelToImageCoord(px));
        redraw();
    }

    /**
     * Shows/hides the explicit "Cancel this shape" toolbar button - the
     * touch-reachable equivalent of Escape (see cancelPendingPolygon()'s
     * own comment for why Escape alone isn't enough on an iPad). Same
     * show-only-while-relevant convention as updateDeleteButton().
     */
    function updatePolygonCancelButton() {
        var btn = document.getElementById('omero-annotate-cancel-polygon');
        if (!btn) {
            return;
        }
        btn.style.display = pendingPolygon ? 'flex' : 'none';
    }

    /**
     * Abandons an in-progress polygon outright, leaving no partial shape
     * behind - shared by three separate triggers that all mean the same
     * thing: the Escape key (works for anyone with a physical/Bluetooth
     * keyboard), switching to a different toolbar tool mid-draw, and the
     * explicit "Cancel this shape" button (see updatePolygonCancelButton())
     * added specifically because most students use iPads with no Escape
     * key at all - Escape alone would have made a part-drawn polygon a
     * genuine dead end for them, reachable only by finishing it (clicking
     * back near the first point) even if that's not what they wanted.
     */
    function cancelPendingPolygon() {
        if (!pendingPolygon) {
            return;
        }
        pendingPolygon = null;
        polygonCursorPx = null;
        redraw();
        updatePolygonCancelButton();
    }

    /**
     * Finishes the in-progress polygon: prompts for an optional label (the
     * same convention every other shape uses) and persists it, or discards
     * it outright if the prompt is cancelled (matching TYPE_POINT's own
     * "cancel means stop" handling).
     */
    function finishPolygon() {
        var points = pendingPolygon.points;
        pendingPolygon = null;
        polygonCursorPx = null;
        redraw();
        updatePolygonCancelButton();

        var label = window.prompt(config.strings.labelprompt, '');
        if (label === null) {
            return;
        }

        ajax('create', {
            type: TYPE_POLYGON,
            points: JSON.stringify(points),
            colour: currentColour,
            label: label,
        }, 'POST').then(function(created) {
            annotations.push(created);
            redraw();
        });
    }

    function onViewportClick(e) {
        var rect = viewportEl.getBoundingClientRect();
        var px = [e.clientX - rect.left, e.clientY - rect.top];

        if (activeTool === TYPE_POLYGON) {
            handlePolygonClick(px);
            return;
        }

        var hit = annotationAtPixel(px);
        if (hit) {
            selectedId = (selectedId === hit.id) ? null : hit.id;
            updateDeleteButton();
            refreshInteractionLock();
            redraw();
            return;
        }

        if (selectedId !== null) {
            // Clicking empty space deselects whatever was selected - this
            // is also the only way out of the rotate-handle state, since
            // that only ever shows for the current selection (reported
            // directly by the user: without this, a selected shape was a
            // dead end with no way to click away from it). Stops here
            // rather than also falling through to point-creation below,
            // so this click just dismisses the selection, not both.
            selectedId = null;
            updateDeleteButton();
            refreshInteractionLock();
            redraw();
            return;
        }

        if (activeTool !== TYPE_POINT) {
            return;
        }

        // A blank/cancelled prompt is still a valid pin - not every mark
        // needs a note - only the AJAX call itself is skipped if the user
        // explicitly cancels (prompt() returns null), matching the usual
        // "cancel means stop" expectation for a native prompt.
        var label = window.prompt(config.strings.labelprompt, '');
        if (label === null) {
            return;
        }

        var coord = olmap.getCoordinateFromPixel(px);
        ajax('create', {
            type: TYPE_POINT,
            x: coord[0],
            y: -coord[1],
            colour: currentColour,
            label: label,
        }, 'POST').then(function(created) {
            annotations.push(created);
            redraw();
        });
    }

    /**
     * @return {Array} [imageX, imageY] for the given viewport-relative pixel.
     */
    function pixelToImageCoord(px) {
        var coord = olmap.getCoordinateFromPixel(px);
        return [coord[0], -coord[1]];
    }

    /**
     * Disables/restores every one of iviewer's own map interactions
     * (pan, zoom, etc.) - used for the whole time the circle tool is
     * selected, not just mid-drag. An earlier version tried to disable
     * them only inside the drag itself (on pointerdown, before starting to
     * track movement), reasoning that 'pointerdown' always fires before the
     * corresponding 'mousedown' for the same gesture and so should
     * pre-empt OL's own drag-pan starting - confirmed live that this does
     * NOT actually stop the map from panning during the drag regardless
     * (OL's own event wiring inside this bundled/minified build isn't
     * ours to inspect, so the exact reason isn't confirmed, only that the
     * pre-empt approach didn't work). Disabling for the tool's entire
     * selected duration sidesteps the whole event-ordering question
     * instead of relying on winning a race against code we can't see -
     * confirmed live this actually stops the map panning during a drag,
     * and that normal panning still works once the tool is deselected.
     * Restores each interaction to whatever it was before (not
     * unconditionally re-enabled), in case something else had already
     * deactivated one.
     *
     * @param {boolean} enabled
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
     * Map interactions need to stay disabled for two separate reasons that
     * can each start/end independently - a shape-drawing tool being active,
     * or an ellipse/rectangle being selected (its rotate handle is
     * draggable) - so this recomputes the combined lock from both current
     * conditions rather than the two call sites trying to toggle a shared
     * boolean directly and stepping on each other.
     */
    function refreshInteractionLock() {
        var locked = isShapeType(activeTool) || activeTool === TYPE_POLYGON || !!selectedRotatableShape();
        setMapInteractionsEnabled(!locked);
    }

    /**
     * The selected ellipse/rectangle, if any - used both to decide whether
     * a rotate handle exists to grab, and to compute where it currently is.
     *
     * @return {object|null}
     */
    function selectedRotatableShape() {
        var selected = annotations.find(function(a) {
            return a.id === selectedId;
        });
        return (selected && isShapeType(selected.type)) ? selected : null;
    }

    /**
     * Dragging the rotate handle: pressing it computes the pointer's
     * current angle around the shape's centre and sets that directly as
     * the rotation (rather than an incremental delta), so the shape
     * visually "grabs" wherever the cursor is on the handle and follows it
     * exactly, matching PowerPoint/Illustrator's own rotate-handle feel.
     * Map interactions are locked for the drag's duration via
     * refreshInteractionLock() (called on selection change, not here -
     * selecting an ellipse/rectangle already locks panning for as long as
     * it stays selected, so there's nothing extra to disable/restore just
     * for the drag itself).
     *
     * @param {PointerEvent} e
     * @param {object} shape An ellipse or rectangle annotation.
     * @return {boolean} True if the press actually hit the handle.
     */
    function tryStartRotateDrag(e, shape) {
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([shape.geometry.x, -shape.geometry.y]);
        var radii = screenRadii([shape.geometry.x, -shape.geometry.y], shape.geometry.rx, shape.geometry.ry);
        // Same view-rotation compounding as redraw() - the handle's own
        // on-screen position has to match what's actually drawn there, or
        // clicking squarely on the visible handle would miss it. Read once
        // for the whole drag (a single pointer obviously can't also be
        // shift-dragging the view at the same time).
        var viewRotation = olmap.getView().getRotation();
        var rotation = (shape.geometry.rotation || 0) + viewRotation;
        var handlePx = handlePosition(centrePx, radii, rotation);

        var pressPx = [e.clientX - rect.left, e.clientY - rect.top];
        var hdx = handlePx[0] - pressPx[0];
        var hdy = handlePx[1] - pressPx[1];
        if (Math.sqrt(hdx * hdx + hdy * hdy) > HIT_RADIUS) {
            return false;
        }

        e.preventDefault();
        e.stopPropagation();

        // The pointer's angle is measured on screen, but geometry.rotation
        // is stored relative to the image's own axes (see redraw()'s own
        // comment) - subtracting the view's rotation back out here is what
        // keeps a rotation edited *while the view happens to be rotated*
        // meaning the same thing later, once the view rotation changes
        // again (e.g. back to 0).
        function angleFor(moveEvent) {
            var movePx = [moveEvent.clientX - rect.left, moveEvent.clientY - rect.top];
            return Math.atan2(movePx[0] - centrePx[0], -(movePx[1] - centrePx[1])) - viewRotation;
        }

        function onMove(moveEvent) {
            shape.geometry.rotation = angleFor(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);

            var finalRotation = angleFor(upEvent);
            shape.geometry.rotation = finalRotation;
            redraw();

            ajax('update', {id: shape.id, rotation: finalRotation}, 'POST');
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * Ellipses/rectangles are drawn by dragging (press at the centre, drag
     * out to set rx/ry, release to finish) rather than a single click,
     * since a click alone can't express a radius. Free dragging sets rx/ry
     * independently (dx and dy tracked separately, not collapsed into one
     * distance) for a real ellipse/rectangle; holding Shift while dragging
     * constrains rx=ry to the larger of the two, for a circle/square -
     * checked continuously via the move/release events' own .shiftKey, so
     * toggling Shift mid-drag updates the live preview immediately rather
     * than only being read once at the start. Built on Pointer Events (not
     * mouse-specific ones), which already made this extend to touch/stylus
     * drawing with no rework - but Shift itself has no touch equivalent,
     * which matters a lot here since most of this plugin's actual students
     * use iPads: the constrainShape toggle button (see buildToolbar()) is
     * checked in the exact same condition, so either one produces the same
     * circle/square result.
     */
    function onViewportPointerDown(e) {
        // Checked regardless of which tool (if any) is currently selected -
        // rotating an already-drawn shape isn't a "tool", it's just what
        // grabbing its handle does whenever one is showing.
        var selected = selectedRotatableShape();
        if (selected && tryStartRotateDrag(e, selected)) {
            return;
        }

        if (!isShapeType(activeTool)) {
            return;
        }
        var drawType = activeTool;

        var rect = viewportEl.getBoundingClientRect();
        var startPx = [e.clientX - rect.left, e.clientY - rect.top];

        // Starting a drag directly on an existing annotation is a select,
        // not a new shape - let the click listener handle it normally.
        if (annotationAtPixel(startPx)) {
            return;
        }

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
            redraw();

            // A drag too small to be deliberate (e.g. a stray click-and-
            // release with barely any movement) is silently discarded
            // rather than saved as a near-invisible shape.
            if (!finished) {
                return;
            }
            var radii = screenRadii([finished.x, -finished.y], finished.rx, finished.ry);
            if (Math.max(radii[0], radii[1]) < HIT_RADIUS) {
                return;
            }

            var label = window.prompt(config.strings.labelprompt, '');
            if (label === null) {
                return;
            }

            ajax('create', {
                type: drawType,
                x: finished.x,
                y: finished.y,
                rx: finished.rx,
                ry: finished.ry,
                colour: currentColour,
                label: label,
            }, 'POST').then(function(created) {
                annotations.push(created);
                redraw();
            });
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
    }

    function updateDeleteButton() {
        var btn = document.getElementById('omero-annotate-delete');
        btn.style.display = selectedId ? 'flex' : 'none';
        btn.style.background = selectedId ? '#e74c3c' : 'transparent';
    }

    /**
     * Shows the "constrain to circle/square" toggle only while it's
     * actually meaningful (the ellipse/rectangle tool selected), and
     * reflects constrainShape's own on/off state the same way the main
     * tool buttons show which tool is active - solid green when on.
     */
    function updateConstrainButtonVisibility() {
        var btn = document.getElementById('omero-annotate-constrain');
        if (!btn) {
            return;
        }
        btn.style.display = isShapeType(activeTool) ? 'flex' : 'none';
        btn.style.background = constrainShape ? '#2ecc71' : 'transparent';
        btn.style.color = constrainShape ? '#000000' : '#ffffff';
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
                refreshInteractionLock();
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

    // Plain inline SVG - deliberately NOT loaded via Moodle's usual pix/
    // <image href> icon path (see tiny_omeroembed's own commands.js and its
    // commit fixing exactly this): an <image href> reference is an isolated
    // external resource with no CSS cascade to inherit "color" from, so
    // fill/stroke="currentColor" can never work through it. Set directly as
    // a button's innerHTML instead, so it's genuinely inline markup in the
    // live DOM and currentColor correctly follows the button's own colour
    // (already toggled light/dark by the same style rules as the button
    // text always was).
    var ICONS = {
        point: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M12 21s-7-7.5-7-12a7 7 0 0 1 14 0c0 4.5-7 12-7 12z"/>'
            + '<circle cx="12" cy="9" r="2.5"/></svg>',
        ellipse: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            + '<ellipse cx="12" cy="12" rx="9" ry="6"/></svg>',
        rectangle: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            + '<rect x="3" y="6" width="18" height="12" rx="1"/></svg>',
        polygon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linejoin="round">'
            + '<path d="M12 2 L22 9 L18 21 L6 21 L2 9 Z"/></svg>',
        deleteIcon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
            + '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
            + '<path d="M10 11v6"/><path d="M14 11v6"/></svg>',
        snapshot: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>'
            + '<circle cx="12" cy="13" r="4"/></svg>',
        constrain: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        cancelIcon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>',
        help: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<circle cx="12" cy="12" r="10"/>'
            + '<path d="M9.5 9a2.5 2.5 0 0 1 4.9 .8c0 1.6-2.4 2-2.4 3.7"/>'
            + '<circle cx="12" cy="17" r="0.6" fill="currentColor" stroke="none"/></svg>',
    };

    /**
     * A plain-language explanation of every toolbar control, reusing the
     * exact same strings each button's own title/tooltip already carries -
     * one source of text, not a second copy to keep in sync. This exists
     * because tooltips are genuinely unreachable on a touch-only device:
     * a title attribute only ever shows on :hover, and there's no hover
     * state on an iPad at all - most of this plugin's actual students -
     * so without this panel, nothing here would explain itself to them.
     * Built as a sibling of viewportEl (appended alongside the toolbar,
     * not inside it), deliberately NOT a child of viewportEl - anything
     * inside viewportEl is reachable by onViewportPointerDown()'s capture-
     * phase listener, which would otherwise treat a tap on this panel's
     * own close button as the start of a new shape drag.
     *
     * @return {HTMLElement}
     */
    function buildHelpPanel() {
        var panel = document.createElement('div');
        panel.id = 'omero-annotate-help';
        panel.style.cssText = 'position:absolute; bottom:6rem; right:1rem; z-index:1001; display:none; '
            + 'background:rgba(0,0,0,0.9); color:#ffffff; padding:0.75rem 1rem; border-radius:4px; '
            + 'max-width:320px; font-size:0.9rem; line-height:1.4;';

        var heading = document.createElement('div');
        heading.style.cssText = 'display:flex; justify-content:space-between; align-items:center; '
            + 'gap:1rem; margin-bottom:0.5rem; font-weight:bold; font-size:1rem;';
        var headingText = document.createElement('span');
        headingText.textContent = config.strings.helptitle;
        heading.appendChild(headingText);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.innerHTML = ICONS.cancelIcon;
        closeBtn.title = config.strings.helpclose;
        closeBtn.style.cssText = 'cursor:pointer; border:none; background:transparent; '
            + 'color:#ffffff; padding:0.3rem; display:flex; flex:0 0 auto;';
        closeBtn.addEventListener('click', function() {
            panel.style.display = 'none';
        });
        heading.appendChild(closeBtn);
        panel.appendChild(heading);

        [
            [ICONS.point, config.strings.placepin],
            [ICONS.ellipse, config.strings.drawellipse],
            [ICONS.rectangle, config.strings.drawrectangle],
            [ICONS.polygon, config.strings.drawpolygon],
            [ICONS.constrain, config.strings.constrainshape],
            [ICONS.cancelIcon, config.strings.cancelpolygon],
            [ICONS.deleteIcon, config.strings['delete']],
            [ICONS.snapshot, config.strings.snapshot],
        ].forEach(function(item) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:flex-start; gap:0.6rem; margin-bottom:0.6rem;';
            var iconWrap = document.createElement('span');
            iconWrap.innerHTML = item[0];
            iconWrap.style.cssText = 'flex:0 0 auto; display:flex; margin-top:0.1rem;';
            var text = document.createElement('span');
            text.textContent = item[1];
            row.appendChild(iconWrap);
            row.appendChild(text);
            panel.appendChild(row);
        });

        return panel;
    }

    function buildToolbar() {
        var toolbar = document.createElement('div');
        toolbar.id = 'omero-annotate-toolbar';
        // bottom:1rem sat right on top of iviewer's own bottom-right corner
        // control (confirmed live) - lifted clear of it rather than
        // overlapping.
        toolbar.style.cssText = 'position:absolute; bottom:3rem; right:1rem; z-index:1000; '
            + 'background:rgba(0,0,0,0.7); padding:0.5rem; border-radius:4px; '
            + 'display:flex; align-items:center; gap:0.5rem;';

        var toolButtons = [];

        /**
         * Both tool buttons share this: a plain border by default, solid
         * green when that tool is the active one - and picking one
         * deactivates the other, since a drag/click on the viewport can
         * only ever mean one thing at a time. The full text label becomes
         * the tooltip (title) rather than visible text, once there's an
         * icon to carry the meaning instead.
         *
         * @param {string} icon SVG markup, from ICONS.
         * @param {string} label Tooltip text.
         * @param {string} tool 'point', 'ellipse', or 'rectangle'
         * @return {HTMLButtonElement}
         */
        function makeToolButton(icon, label, tool) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.innerHTML = icon;
            btn.title = label;
            btn.style.cssText = 'cursor:pointer; display:flex; border:1px solid #ffffff; '
                + 'border-radius:3px; padding:0.3rem;';

            function update() {
                var isActive = activeTool === tool;
                btn.style.background = isActive ? '#2ecc71' : 'transparent';
                btn.style.color = isActive ? '#000000' : '#ffffff';
            }

            btn.addEventListener('click', function() {
                // Switching tools mid-draw abandons any in-progress
                // polygon outright, same as Escape/Cancel - a part-drawn
                // polygon left dangling while a different tool takes over
                // would be a confusing, orphaned state.
                cancelPendingPolygon();
                activeTool = (activeTool === tool) ? null : tool;
                refreshInteractionLock();
                updateConstrainButtonVisibility();
                toolButtons.forEach(function(b) {
                    b.update();
                });
            });

            btn.update = update;
            update();
            toolButtons.push(btn);
            return btn;
        }

        toolbar.appendChild(makeToolButton(ICONS.point, config.strings.placepin, TYPE_POINT));
        toolbar.appendChild(makeToolButton(ICONS.ellipse, config.strings.drawellipse, TYPE_ELLIPSE));
        toolbar.appendChild(makeToolButton(ICONS.rectangle, config.strings.drawrectangle, TYPE_RECTANGLE));
        toolbar.appendChild(makeToolButton(ICONS.polygon, config.strings.drawpolygon, TYPE_POLYGON));

        // Touch-reachable equivalent of holding Shift while drawing an
        // ellipse/rectangle (see onViewportPointerDown()'s own comment) -
        // only shown while one of those two tools is actually selected,
        // same as the delete button only showing while something is
        // selected. Starts hidden; updateConstrainButtonVisibility() (also
        // called from the tool buttons' own click handler) reveals it.
        var constrainBtn = document.createElement('button');
        constrainBtn.type = 'button';
        constrainBtn.id = 'omero-annotate-constrain';
        constrainBtn.innerHTML = ICONS.constrain;
        constrainBtn.title = config.strings.constrainshape;
        constrainBtn.style.cssText = 'cursor:pointer; display:none; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff; background:transparent;';
        constrainBtn.addEventListener('click', function() {
            constrainShape = !constrainShape;
            updateConstrainButtonVisibility();
        });
        toolbar.appendChild(constrainBtn);

        // Touch-reachable equivalent of pressing Escape mid-polygon (see
        // cancelPendingPolygon()'s own comment) - starts hidden,
        // updatePolygonCancelButton() reveals it only while a polygon is
        // actually in progress.
        var cancelPolygonBtn = document.createElement('button');
        cancelPolygonBtn.type = 'button';
        cancelPolygonBtn.id = 'omero-annotate-cancel-polygon';
        cancelPolygonBtn.innerHTML = ICONS.cancelIcon;
        cancelPolygonBtn.title = config.strings.cancelpolygon;
        cancelPolygonBtn.style.cssText = 'cursor:pointer; display:none; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff; background:transparent;';
        cancelPolygonBtn.addEventListener('click', cancelPendingPolygon);
        toolbar.appendChild(cancelPolygonBtn);

        COLOURS.forEach(function(colour) {
            var swatch = document.createElement('button');
            swatch.type = 'button';
            swatch.title = colour;
            swatch.dataset.swatch = '1';
            swatch.style.cssText = 'width:1.2rem; height:1.2rem; border-radius:50%; padding:0; '
                + 'cursor:pointer; background:' + colour + '; border:2px solid transparent;';
            if (colour === currentColour) {
                swatch.style.borderColor = '#ffffff';
            }
            swatch.addEventListener('click', function() {
                currentColour = colour;
                toolbar.querySelectorAll('button[data-swatch]').forEach(function(b) {
                    b.style.borderColor = 'transparent';
                });
                swatch.style.borderColor = '#ffffff';
            });
            toolbar.appendChild(swatch);
        });

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.id = 'omero-annotate-delete';
        deleteBtn.innerHTML = ICONS.deleteIcon;
        deleteBtn.title = config.strings['delete'];
        deleteBtn.style.cssText = 'cursor:pointer; display:none; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff; background:transparent;';
        deleteBtn.addEventListener('click', deleteSelected);
        toolbar.appendChild(deleteBtn);

        var snapshotBtn = document.createElement('button');
        snapshotBtn.type = 'button';
        snapshotBtn.innerHTML = ICONS.snapshot;
        snapshotBtn.title = config.strings.snapshot;
        // Confirmed live: without an explicit background here, this falls
        // back to the browser/theme's own default button background - in
        // a light ("day") Moodle theme that default is light/white,
        // making the white currentColor icon invisible against it. Every
        // other toolbar button already sets its own background
        // explicitly (transparent or a specific colour) for exactly this
        // reason - this one just missed it.
        snapshotBtn.style.cssText = 'cursor:pointer; display:flex; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff; background:transparent;';
        snapshotBtn.addEventListener('click', takeSnapshot);
        toolbar.appendChild(snapshotBtn);

        var helpPanel = buildHelpPanel();

        var helpBtn = document.createElement('button');
        helpBtn.type = 'button';
        helpBtn.innerHTML = ICONS.help;
        helpBtn.title = config.strings.help;
        helpBtn.style.cssText = 'cursor:pointer; display:flex; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff; background:transparent;';
        helpBtn.addEventListener('click', function() {
            helpPanel.style.display = (helpPanel.style.display === 'none') ? 'block' : 'none';
        });
        toolbar.appendChild(helpBtn);

        viewportEl.parentNode.insertBefore(toolbar, viewportEl.nextSibling);
        // A sibling of viewportEl, not a child - see buildHelpPanel()'s own
        // docblock for why that matters.
        viewportEl.parentNode.insertBefore(helpPanel, viewportEl.nextSibling);
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

        var labelEl = document.createElement('div');
        labelEl.id = 'omero-annotate-label';
        labelEl.style.cssText = 'position:absolute; display:none; z-index:1001; '
            + 'transform:translate(-50%, -100%); white-space:nowrap; '
            + 'background:rgba(0,0,0,0.85); color:#ffffff; padding:0.2rem 0.5rem; '
            + 'border-radius:3px; font-size:0.85rem; pointer-events:none;';
        viewportEl.appendChild(labelEl);

        // Clicks/pointerdowns reach these listeners even though
        // overlayCanvas sits visually on top - pointer-events:none makes it
        // pass every pointer event straight through. onViewportPointerDown
        // only takes over (and only then temporarily disables iviewer's own
        // pan/zoom interactions) while a circle is actively being drawn -
        // it's a no-op otherwise, so normal panning is unaffected. Capture
        // phase - see that function's own docblock for why.
        viewportEl.addEventListener('click', onViewportClick);
        viewportEl.addEventListener('pointerdown', onViewportPointerDown, true);

        // Live rubber-band preview while a polygon is being placed - a
        // plain bubble-phase listener is enough since this only ever
        // reads the cursor position, never needs to intercept/block
        // anything else.
        viewportEl.addEventListener('pointermove', function(e) {
            if (!pendingPolygon) {
                return;
            }
            var rect = viewportEl.getBoundingClientRect();
            polygonCursorPx = [e.clientX - rect.left, e.clientY - rect.top];
            redraw();
        });

        // Escape cancels an in-progress polygon outright, rather than
        // leaving a part-drawn shape with no way out of it - the same
        // "there must always be a way to back out" reasoning behind
        // deselect-on-click-elsewhere. Only reachable with a physical/
        // Bluetooth keyboard though - see the toolbar's own "Cancel this
        // shape" button (buildToolbar()) for the touch-reachable
        // equivalent, essential given most students here use iPads.
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cancelPendingPolygon();
            }
        });

        olmap.on('postrender', redraw);

        buildToolbar();

        ajax('list', {}, 'GET').then(function(existing) {
            annotations = existing;
            redraw();
        });
    }

    init();
})();
