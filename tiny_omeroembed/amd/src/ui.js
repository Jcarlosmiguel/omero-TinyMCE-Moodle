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
 * Tiny OMERO Embed dialog handling.
 *
 * Opens local_omeroembed's own author.php (subject/image picker, live
 * pan-zoom preview, view-link insertion) inside a modal iframe, and inserts
 * the embed HTML it posts back into the editor. See author.php/js/author.js
 * for the other half of this handoff (window.parent.postMessage(...) when
 * config.embedded is true).
 *
 * If the cursor is on/inside an existing embed (marked with
 * data-omero-embed by author.js's own generateEmbed()), its settings and
 * write-up text are read directly out of the DOM and used to pre-fill the
 * modal, and the modal's result replaces that embed in place instead of
 * inserting a new one alongside it - modelled on tiny_h5p's own
 * getCurrentH5PData()/closest('.h5p-placeholder') pattern, adapted because
 * our embed is a two-part iframe+write-up structure rather than one atomic
 * node.
 *
 * @module      tiny_omeroembed/ui
 * @copyright   2026 University of Glasgow MVLS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getContextId} from 'editor_tiny/options';
import Config from 'core/config';
import Modal from './modal';
import ModalEvents from 'core/modal_events';

const MESSAGE_TYPE = 'omero-embed-html';
const READY_MESSAGE_TYPE = 'omero-embed-ready';
const WRITEUP_MESSAGE_TYPE = 'omero-embed-existing-writeup';

// Matches author.php's own $overlaysettings key list (proxy.php's
// resolve_overlay_setting() is the actual precedence logic - this file
// only needs to round-trip whatever an existing embed already has).
const OVERLAY_PARAM_KEYS = [
    'hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline',
    'hidezoom', 'hidenavbar', 'showomerorois', 'enableannotations',
    'enablehotspot', 'enablehotspotmulti',
];

/**
 * Reads an existing embed's settings and write-up text straight out of the
 * DOM - no server round trip needed, everything generateEmbed() put there is
 * still right there in the editor's own content.
 *
 * @param {Element} node the [data-omero-embed] wrapper
 * @return {object|null} {params, writeupHtml} or null if the iframe/src can't
 *         be parsed (e.g. a hand-edited/corrupted embed)
 */
const readExistingEmbed = (node) => {
    const iframe = node.querySelector('iframe');
    if (!iframe) {
        return null;
    }

    let url;
    try {
        url = new URL(iframe.getAttribute('src'), window.location.href);
    } catch (e) {
        return null;
    }

    const pathParts = url.pathname.split('/');
    const proxyIndex = pathParts.indexOf('proxy.php');
    if (proxyIndex === -1 || !pathParts[proxyIndex + 2]) {
        return null;
    }
    const subject = decodeURIComponent(pathParts[proxyIndex + 2]);

    const table = node.querySelector('table');
    let layout = 'imageonly';
    let writeupHtml = null;
    if (table) {
        const cells = Array.from(table.querySelectorAll('tr > td'));
        const iframeCellIndex = cells.findIndex((cell) => cell.querySelector('iframe'));
        layout = iframeCellIndex === 0 ? 'slideleft' : 'slideright';
        const writeupCell = cells.find((cell) => !cell.querySelector('iframe'));
        if (writeupCell) {
            writeupHtml = writeupCell.innerHTML;
        }
    } else if (node.querySelector('button')) {
        // No table, but a reset button is only ever generated for the
        // textbelow layout (see author.js's generateEmbed()) - the write-up
        // is the other <div> child, alongside the iframe and the button.
        layout = 'textbelow';
        const writeupDiv = Array.from(node.children).find((child) => child.tagName === 'DIV');
        if (writeupDiv) {
            writeupHtml = writeupDiv.innerHTML;
        }
    }

    const params = {
        subject,
        images: url.searchParams.get('images') || '',
        dataset: url.searchParams.get('dataset') || '',
        browsable: url.searchParams.has('browsable'),
        layout,
        width: node.style.maxWidth || '',
        height: iframe.style.height || '',
        // Read back so re-editing reuses author.js's getOrMintAnnotateId()
        // token instead of minting a fresh one, which would otherwise
        // orphan this embed's existing student annotations - see that
        // file's own comment on embedAnnotateId for the full reasoning.
        annotateid: node.getAttribute('data-omero-annotate-id') || '',
    };

    // Per-embed overlay/annotation overrides (see author.php's own
    // $overlaysettings comment) - only added when actually present in the
    // existing embed's src, which an embed generated before this feature
    // existed never has. Left absent rather than guessed at, so
    // author.php's own optional_param($key, null, ...) correctly falls
    // back to the current site default for those - same "explicit value
    // always wins, absence means no opinion" rule proxy.php's
    // resolve_overlay_setting() already applies when actually rendering.
    OVERLAY_PARAM_KEYS.forEach((key) => {
        if (url.searchParams.has(key)) {
            params[key] = url.searchParams.get(key) === '1';
        }
    });

    // Same "only forwarded when actually present" reasoning as the overlay
    // keys above - a comma-separated hex list rather than a single '1'/'0',
    // so it's read separately.
    if (url.searchParams.has('annotationcolours')) {
        params.annotationcolours = url.searchParams.get('annotationcolours');
    }

    return {params, writeupHtml};
};

/**
 * A fresh insert lands exactly at the cursor - if that happens to be inside
 * a table cell (an old, unmarked omero embed predating data-omero-embed, or
 * any other table entirely), inserting our own multi-part table+iframe
 * there would nest it inside that cell instead of landing as a normal block
 * on the page - confirmed live, this is exactly what happened with a real
 * pre-existing embed. Moves the insertion point to just after the
 * outermost enclosing table instead, so a fresh insert always lands as a
 * sibling, never nested - a no-op when the cursor isn't in a table at all,
 * which is the common case.
 *
 * @param {Editor} editor
 */
const escapeEnclosingTable = (editor) => {
    let outerTable = null;
    let table = editor.selection.getNode().closest('table');
    while (table) {
        outerTable = table;
        table = table.parentNode ? table.parentNode.closest('table') : null;
    }
    if (outerTable) {
        editor.selection.select(outerTable);
        editor.selection.collapse(false);
    }
};

export const handleAction = async(editor) => {
    const contextId = getContextId(editor);
    const existingNode = editor.selection.getNode().closest('[data-omero-embed]');
    const existing = existingNode ? readExistingEmbed(existingNode) : null;
    if (!existingNode) {
        escapeEnclosingTable(editor);
    }

    let iframeUrl = Config.wwwroot + '/local/omeroembed/author.php?contextid=' + contextId + '&embedded=1';
    if (existing) {
        const p = existing.params;
        iframeUrl += '&subject=' + encodeURIComponent(p.subject)
            + '&images=' + encodeURIComponent(p.images)
            + '&dataset=' + encodeURIComponent(p.dataset)
            + '&layout=' + encodeURIComponent(p.layout)
            + '&width=' + encodeURIComponent(p.width)
            + '&height=' + encodeURIComponent(p.height)
            + (p.browsable ? '&browsable=1' : '')
            + (p.annotateid ? '&annotateid=' + encodeURIComponent(p.annotateid) : '');
        // Only forwarded when readExistingEmbed() actually found them (an
        // embed from before this feature existed won't have any) - see
        // that function's own comment on why absence is left as absence,
        // not guessed at.
        OVERLAY_PARAM_KEYS.forEach((key) => {
            if (Object.prototype.hasOwnProperty.call(p, key)) {
                iframeUrl += '&' + key + '=' + (p[key] ? '1' : '0');
            }
        });
        if (Object.prototype.hasOwnProperty.call(p, 'annotationcolours')) {
            iframeUrl += '&annotationcolours=' + encodeURIComponent(p.annotationcolours);
        }
    }

    const modal = await Modal.create({
        templateContext: {iframeurl: iframeUrl},
    });

    let messageListener = null;

    const cleanup = () => {
        if (messageListener) {
            window.removeEventListener('message', messageListener);
            messageListener = null;
        }
    };

    messageListener = (event) => {
        if (event.origin !== window.location.origin) {
            return;
        }
        if (!event.data || !event.data.type) {
            return;
        }

        if (event.data.type === READY_MESSAGE_TYPE) {
            // Only meaningful once the modal's iframe is actually listening -
            // see author.js's own handshake for why.
            if (existing && existing.writeupHtml) {
                const iframeEl = modal.getRoot()[0].querySelector('iframe');
                if (iframeEl && iframeEl.contentWindow) {
                    iframeEl.contentWindow.postMessage(
                        {type: WRITEUP_MESSAGE_TYPE, html: existing.writeupHtml},
                        window.location.origin
                    );
                }
            }
            return;
        }

        if (event.data.type === MESSAGE_TYPE) {
            if (existingNode) {
                editor.dom.setOuterHTML(existingNode, event.data.html);
            } else {
                editor.insertContent(event.data.html);
            }
            cleanup();
            modal.destroy();
        }
    };
    window.addEventListener('message', messageListener);

    modal.getRoot().on(ModalEvents.hidden, cleanup);
};
