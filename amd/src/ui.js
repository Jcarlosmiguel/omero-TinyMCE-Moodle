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
 * @module      tiny_omeroembed/ui
 * @copyright   2026 University of Glasgow MVLS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getContextId} from 'editor_tiny/options';
import Config from 'core/config';
import Modal from './modal';
import ModalEvents from 'core/modal_events';

const MESSAGE_TYPE = 'omero-embed-html';

export const handleAction = async(editor) => {
    const contextId = getContextId(editor);
    const iframeUrl = Config.wwwroot + '/local/omeroembed/author.php?contextid=' + contextId + '&embedded=1';

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
        if (!event.data || event.data.type !== MESSAGE_TYPE) {
            return;
        }
        editor.insertContent(event.data.html);
        cleanup();
        modal.destroy();
    };
    window.addEventListener('message', messageListener);

    modal.getRoot().on(ModalEvents.hidden, cleanup);
};
