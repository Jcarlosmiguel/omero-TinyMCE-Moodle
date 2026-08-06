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

    var TYPE_ELLIPSE = 'ellipse';
    var TYPE_RECTANGLE = 'rectangle';
    var HIT_RADIUS = 12;

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

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.textContent = 'Clear';
        clearBtn.title = 'Clear';
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
        statusEl.textContent = 'Draw the correct answer region';
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
