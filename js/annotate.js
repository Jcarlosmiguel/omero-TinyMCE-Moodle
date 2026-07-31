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
 * Student point/ellipse annotations, drawn directly on the live iviewer
 * view. Loaded only on the final student-facing embed (see proxy.php's
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
 * Ellipses, unlike points, are stored with their radii in real
 * image-pixel units (geometry {x,y,rx,ry}), so their on-screen size grows
 * and shrinks with zoom like an actual measured region of the slide -
 * the opposite of a pin, and the reason they need their own drag-to-draw
 * gesture (press/move/release) rather than a single click. Free dragging
 * sets rx/ry independently (a real ellipse); holding Shift while dragging
 * constrains rx=ry to a circle - the same convention as Illustrator/
 * PowerPoint/Figma's own shape tools, so a circle is just the equal-radii
 * case rather than a separate shape/type.
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
    var HANDLE_OFFSET = 20; // px beyond the ellipse's own edge, along its rotation axis
    var COLOURS = ['#e6194B', '#3cb44b', '#4363d8', '#f58231', '#911eb4', '#000000'];
    var TYPE_POINT = 'point';
    var TYPE_ELLIPSE = 'ellipse';

    var annotations = [];
    var selectedId = null;
    var currentColour = COLOURS[0];
    var activeTool = null; // null | 'point' | 'ellipse'
    var pendingEllipse = null; // {x, y, rx, ry} in image coords while drag-drawing, else null
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
     * Where the rotate handle sits on screen for a selected ellipse: a
     * point HANDLE_OFFSET beyond the top of the ellipse's own (unrotated)
     * local frame, then rotated to the ellipse's current angle - matching
     * PowerPoint/Illustrator's own handle-above-the-shape convention. All
     * in screen pixels; rotation here is a plain canvas-style angle
     * (radians, clockwise from the local "up" direction).
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
     * @param {number} px The click x, relative to an ellipse's own centre.
     * @param {number} py The click y, relative to an ellipse's own centre.
     * @param {number} rotation Radians.
     * @return {Array} [px, py] rotated into the ellipse's own unrotated
     *                 local frame, so the plain axis-aligned point-in-
     *                 ellipse formula still applies regardless of rotation.
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

    function redraw() {
        resizeOverlay();
        var ctx = overlayCanvas.getContext('2d');
        ctx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);

        var selectedPx = null;
        var selectedTopY = null;
        annotations.forEach(function(a) {
            var px = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!px) {
                return;
            }

            if (a.type === TYPE_ELLIPSE) {
                var rotation = a.geometry.rotation || 0;
                var radii = screenRadii([a.geometry.x, -a.geometry.y], a.geometry.rx, a.geometry.ry);
                ctx.beginPath();
                ctx.ellipse(px[0], px[1], radii[0], radii[1], rotation, 0, 2 * Math.PI);
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

        if (pendingEllipse) {
            var centrePx = olmap.getPixelFromCoordinate([pendingEllipse.x, -pendingEllipse.y]);
            var pendingRadii = screenRadii([pendingEllipse.x, -pendingEllipse.y], pendingEllipse.rx, pendingEllipse.ry);
            if (centrePx) {
                ctx.beginPath();
                ctx.ellipse(centrePx[0], centrePx[1], pendingRadii[0], pendingRadii[1], 0, 0, 2 * Math.PI);
                ctx.setLineDash([4, 4]);
                ctx.lineWidth = 2;
                ctx.strokeStyle = currentColour;
                ctx.stroke();
                ctx.setLineDash([]);
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

    function annotationAtPixel(px) {
        for (var i = annotations.length - 1; i >= 0; i--) {
            var a = annotations[i];
            var apx = olmap.getPixelFromCoordinate([a.geometry.x, -a.geometry.y]);
            if (!apx) {
                continue;
            }
            var dx = apx[0] - px[0];
            var dy = apx[1] - px[1];
            var dist = Math.sqrt(dx * dx + dy * dy);

            if (a.type === TYPE_ELLIPSE) {
                var radii = screenRadii([a.geometry.x, -a.geometry.y], a.geometry.rx, a.geometry.ry);
                // Rotate the click into the ellipse's own unrotated local
                // frame first, so the plain axis-aligned formula below
                // still applies regardless of rotation.
                var local = unrotate(dx, dy, a.geometry.rotation || 0);
                // Clamp each axis to at least HIT_RADIUS so a thin/tiny
                // ellipse is still easy to click, same reasoning as a
                // circle's own Math.max() before it - a proper point-in-
                // ellipse test rather than a plain circular one, since rx
                // and ry can now genuinely differ.
                var rx = Math.max(radii[0], HIT_RADIUS);
                var ry = Math.max(radii[1], HIT_RADIUS);
                var normalised = (local[0] * local[0]) / (rx * rx) + (local[1] * local[1]) / (ry * ry);
                if (normalised <= 1) {
                    return a;
                }
            } else if (dist <= HIT_RADIUS) {
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
     * can each start/end independently - the ellipse tool being active
     * (drawing) and an ellipse being selected (its rotate handle is
     * draggable) - so this recomputes the combined lock from both current
     * conditions rather than the two call sites trying to toggle a shared
     * boolean directly and stepping on each other.
     */
    function refreshInteractionLock() {
        var locked = (activeTool === TYPE_ELLIPSE) || !!selectedEllipse();
        setMapInteractionsEnabled(!locked);
    }

    /**
     * The selected ellipse, if any - used both to decide whether a rotate
     * handle exists to grab, and to compute where it currently is.
     *
     * @return {object|null}
     */
    function selectedEllipse() {
        var selected = annotations.find(function(a) {
            return a.id === selectedId;
        });
        return (selected && selected.type === TYPE_ELLIPSE) ? selected : null;
    }

    /**
     * Dragging the rotate handle: pressing it computes the pointer's
     * current angle around the ellipse's centre and sets that directly as
     * the rotation (rather than an incremental delta), so the shape
     * visually "grabs" wherever the cursor is on the handle and follows it
     * exactly, matching PowerPoint/Illustrator's own rotate-handle feel.
     * Map interactions are locked for the drag's duration via
     * refreshInteractionLock() (called on selection change, not here -
     * selecting an ellipse already locks panning for as long as it stays
     * selected, so there's nothing extra to disable/restore just for the
     * drag itself).
     *
     * @param {PointerEvent} e
     * @param {object} ellipse
     * @return {boolean} True if the press actually hit the handle.
     */
    function tryStartRotateDrag(e, ellipse) {
        var rect = viewportEl.getBoundingClientRect();
        var centrePx = olmap.getPixelFromCoordinate([ellipse.geometry.x, -ellipse.geometry.y]);
        var radii = screenRadii([ellipse.geometry.x, -ellipse.geometry.y], ellipse.geometry.rx, ellipse.geometry.ry);
        var rotation = ellipse.geometry.rotation || 0;
        var handlePx = handlePosition(centrePx, radii, rotation);

        var pressPx = [e.clientX - rect.left, e.clientY - rect.top];
        var hdx = handlePx[0] - pressPx[0];
        var hdy = handlePx[1] - pressPx[1];
        if (Math.sqrt(hdx * hdx + hdy * hdy) > HIT_RADIUS) {
            return false;
        }

        e.preventDefault();
        e.stopPropagation();

        function angleFor(moveEvent) {
            var movePx = [moveEvent.clientX - rect.left, moveEvent.clientY - rect.top];
            return Math.atan2(movePx[0] - centrePx[0], -(movePx[1] - centrePx[1]));
        }

        function onMove(moveEvent) {
            ellipse.geometry.rotation = angleFor(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);

            var finalRotation = angleFor(upEvent);
            ellipse.geometry.rotation = finalRotation;
            redraw();

            ajax('update', {id: ellipse.id, rotation: finalRotation}, 'POST');
        }

        window.addEventListener('pointermove', onMove, true);
        window.addEventListener('pointerup', onUp, true);
        return true;
    }

    /**
     * Ellipses are drawn by dragging (press at the centre, drag out to set
     * rx/ry, release to finish) rather than a single click, since a click
     * alone can't express a radius. Free dragging sets rx/ry independently
     * (dx and dy tracked separately, not collapsed into one distance) for a
     * real ellipse; holding Shift while dragging constrains rx=ry to the
     * larger of the two, for a circle - checked continuously via the move/
     * release events' own .shiftKey, so toggling Shift mid-drag updates the
     * live preview immediately rather than only being read once at the
     * start. Built on Pointer Events (not mouse-specific ones) so this
     * extends to touch/stylus input later with no rework needed here.
     */
    function onViewportPointerDown(e) {
        // Checked regardless of which tool (if any) is currently selected -
        // rotating an already-drawn ellipse isn't a "tool", it's just what
        // grabbing its handle does whenever one is showing.
        var selected = selectedEllipse();
        if (selected && tryStartRotateDrag(e, selected)) {
            return;
        }

        if (activeTool !== TYPE_ELLIPSE) {
            return;
        }

        var rect = viewportEl.getBoundingClientRect();
        var startPx = [e.clientX - rect.left, e.clientY - rect.top];

        // Starting a drag directly on an existing annotation is a select,
        // not a new ellipse - let the click listener handle it normally.
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
            if (moveEvent.shiftKey) {
                rx = ry = Math.max(rx, ry);
            }
            return {x: startCoord[0], y: startCoord[1], rx: rx, ry: ry};
        }

        function onMove(moveEvent) {
            pendingEllipse = computePending(moveEvent);
            redraw();
        }

        function onUp(upEvent) {
            window.removeEventListener('pointermove', onMove, true);
            window.removeEventListener('pointerup', onUp, true);

            var finished = pendingEllipse ? computePending(upEvent) : null;
            pendingEllipse = null;
            redraw();

            // A drag too small to be deliberate (e.g. a stray click-and-
            // release with barely any movement) is silently discarded
            // rather than saved as a near-invisible ellipse.
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
                type: TYPE_ELLIPSE,
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
        deleteIcon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
            + '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
            + '<path d="M10 11v6"/><path d="M14 11v6"/></svg>',
        snapshot: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>'
            + '<circle cx="12" cy="13" r="4"/></svg>',
    };

    function buildToolbar() {
        var toolbar = document.createElement('div');
        toolbar.id = 'omero-annotate-toolbar';
        toolbar.style.cssText = 'position:absolute; bottom:1rem; right:1rem; z-index:1000; '
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
         * @param {string} tool 'point' or 'ellipse'
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
                activeTool = (activeTool === tool) ? null : tool;
                refreshInteractionLock();
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
            + 'border-radius:3px; padding:0.3rem; color:#ffffff;';
        deleteBtn.addEventListener('click', deleteSelected);
        toolbar.appendChild(deleteBtn);

        var snapshotBtn = document.createElement('button');
        snapshotBtn.type = 'button';
        snapshotBtn.innerHTML = ICONS.snapshot;
        snapshotBtn.title = config.strings.snapshot;
        snapshotBtn.style.cssText = 'cursor:pointer; display:flex; border:1px solid #ffffff; '
            + 'border-radius:3px; padding:0.3rem; color:#ffffff;';
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

        olmap.on('postrender', redraw);

        buildToolbar();

        ajax('list', {}, 'GET').then(function(existing) {
            annotations = existing;
            redraw();
        });
    }

    init();
})();
