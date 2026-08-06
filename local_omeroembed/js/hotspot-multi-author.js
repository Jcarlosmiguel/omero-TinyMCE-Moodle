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
 * centre, drag out a radius, release - same formula, still rotation 0
 * always) for drawing each individual region, wrapped in the multi-shape
 * UX js/annotate.js already established: the Ellipse/Rectangle tool stays
 * active across draws so a teacher can draw region after region without
 * re-selecting the tool, one overlay canvas re-draws the whole set on
 * every 'postrender', and clicking an existing region selects it (a
 * simplified, non-rotating, non-polygon version of annotate.js's own
 * annotationAtPixel()) for a "Delete selected" button.
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

    function traceShape(ctx, type, cx, cy, rx, ry) {
        if (type === TYPE_ELLIPSE) {
            ctx.ellipse(cx, cy, rx, ry, 0, 0, Math.PI * 2);
        } else {
            ctx.rect(cx - rx, cy - ry, rx * 2, ry * 2);
        }
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

        regions.forEach(function(region, index) {
            var radii = screenRadii(region.x, region.y, region.rx, region.ry);
            var px = olmap.getPixelFromCoordinate([region.x, -region.y]);
            if (!px) {
                return;
            }
            ctx.save();
            ctx.strokeStyle = (index === selectedIndex) ? '#3cb44b' : '#f5a623';
            ctx.lineWidth = (index === selectedIndex) ? 3 : 2.5;
            ctx.setLineDash([8, 5]);
            ctx.beginPath();
            traceShape(ctx, region.type, px[0], px[1], radii[0], radii[1]);
            ctx.stroke();
            ctx.restore();
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
                traceShape(ctx, pendingShape.type, pendingPx[0], pendingPx[1], pendingRadii[0], pendingRadii[1]);
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
            if (region.type === TYPE_ELLIPSE) {
                var normalised = (dx * dx) / (rx * rx) + (dy * dy) / (ry * ry);
                if (normalised <= 1) {
                    return i;
                }
            } else if (Math.abs(dx) <= rx && Math.abs(dy) <= ry) {
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
     * Drag-to-draw: press at the centre, drag out a radius, release - same
     * gesture and geometry formula as hotspot-author.js's own
     * onViewportPointerDown()/computePending().
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
