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
 * Multi-region click-to-answer hotspot feature: qtype_omerohotspotmulti's
 * own question-edit-form authoring side. A sibling of js/hotspot-qtype-
 * author.js, not a mode of it - reuses js/hotspot-multi-author.js's own
 * multi-shape drawing/select/delete mechanics (persistent tool, full-
 * redraw loop over an array, click-to-select + delete), but reports the
 * whole current array to the parent *question edit form* via postMessage
 * instead of POSTing to ajax.php's hotspotmulti_save - there is no
 * embedid, no server round-trip, and nothing persisted until the whole
 * question form is submitted (the geometry is just one more form field,
 * same as the single-region qtype's own hidden 'geometry' field).
 *
 * Loaded only inside qtype_omerohotspotmulti's edit-form preview iframe
 * (see proxy.php's inject_hotspot_multi_edit_form_script(), gated on
 * local/omeroembed:hotspotauthor - same capability every other hotspot
 * authoring script in this plugin requires).
 *
 * @module     local_omeroembed/hotspot-multi-qtype-author
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    'use strict';

    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var HIT_RADIUS = 12;

    var olmap = null;
    var viewportEl = null;
    var overlayCanvas = null;
    var activeTool = null;
    var constrainShape = false;
    var pendingShape = null;
    var regions = [];
    var selectedIndex = -1;
    var disabledInteractions = null;
    var deleteBtn = null;

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

    function screenRadii(centreX, centreY, rx, ry) {
        var centrePx = olmap.getPixelFromCoordinate([centreX, -centreY]);
        var edgePx = olmap.getPixelFromCoordinate([centreX + rx, -(centreY + ry)]);
        if (!centrePx || !edgePx) {
            return [0, 0];
        }
        return [Math.abs(edgePx[0] - centrePx[0]), Math.abs(edgePx[1] - centrePx[1])];
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

    /**
     * Posts the whole current array up to the parent edit form - the one
     * genuine difference from js/hotspot-multi-author.js, which POSTs to
     * ajax.php's hotspotmulti_save instead. No confirmation round-trip
     * needed: the parent just writes this straight into its own hidden
     * field.
     */
    function reportRegions() {
        window.parent.postMessage({type: 'omero-hotspot-multi-geometry', geometry: regions}, window.location.origin);
    }

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
                redraw();
                return;
            }

            regions.push(finished);
            redraw();
            reportRegions();
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

        toolbar.appendChild(makeToolButton('Ellipse', TYPE_ELLIPSE));
        toolbar.appendChild(makeToolButton('Rectangle', TYPE_RECTANGLE));

        var constrainBtn = document.createElement('button');
        constrainBtn.type = 'button';
        constrainBtn.textContent = '○=□';
        constrainBtn.title = 'Constrain to a circle/square';
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
        deleteBtn.textContent = 'Delete region';
        deleteBtn.title = 'Delete selected region';
        deleteBtn.style.cssText = 'cursor:pointer; border:1px solid #e74c3c; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:#e74c3c; display:none;';
        deleteBtn.addEventListener('click', function() {
            if (selectedIndex < 0) {
                return;
            }
            regions.splice(selectedIndex, 1);
            selectedIndex = -1;
            updateDeleteButton();
            redraw();
            reportRegions();
        });
        toolbar.appendChild(deleteBtn);

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.textContent = 'Clear';
        clearBtn.title = 'Clear all regions';
        clearBtn.style.cssText = 'cursor:pointer; border:1px solid #ffffff; border-radius:3px; '
            + 'padding:0.3rem 0.6rem; font-size:0.8rem; color:#ffffff; background:transparent;';
        clearBtn.addEventListener('click', function() {
            regions = [];
            selectedIndex = -1;
            updateDeleteButton();
            redraw();
            reportRegions();
        });
        toolbar.appendChild(clearBtn);

        var statusEl = document.createElement('span');
        statusEl.id = 'omero-hotspotmulti-status';
        statusEl.style.cssText = 'color:#2ecc71; font-size:0.8rem;';
        statusEl.textContent = 'Draw one or more correct answer regions';
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

        // Same "ready, then receive" handshake js/hotspot-qtype-author.js's
        // own single-region equivalent already establishes - this iframe
        // has no way to know an existing question's already-saved regions
        // on its own.
        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) {
                return;
            }
            if (!event.data || event.data.type !== 'omero-hotspot-multi-load-geometry') {
                return;
            }
            regions = event.data.geometry || [];
            redraw();
        });
        window.parent.postMessage({type: 'omero-hotspot-multi-author-ready'}, window.location.origin);
    }

    init();
}());
