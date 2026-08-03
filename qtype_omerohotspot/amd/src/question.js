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
 * Question class for the OMERO hotspot question type, used to support the
 * question attempt/preview pages - same role as qtype_ddmarker's own
 * amd/src/question.js, adapted for a cross-frame (iframe + postMessage)
 * source of input rather than same-page drag-and-drop.
 *
 * @module     qtype_omerohotspot/question
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import FormChangeChecker from 'core_form/changechecker';

/**
 * @param {String} containerId id of the outer div for this question (question-<usageid>-<slot>).
 * @param {String} wrapId id of this question's own iframe-wrapping div.
 * @param {Boolean} readonly true on a review/read-only display - clicks are ignored.
 */
export const init = (containerId, wrapId, readonly) => {
    const container = document.getElementById(containerId);
    const wrap = document.getElementById(wrapId);
    if (!container || !wrap || readonly) {
        // Nothing to wire up on a read-only display - the previously
        // submitted click (if any) is already baked into the hidden
        // fields' own value attribute server-side.
        return;
    }

    const clickxInput = container.querySelector('.qtype_omerohotspot_clickx');
    const clickyInput = container.querySelector('.qtype_omerohotspot_clicky');
    const iframe = wrap.querySelector('iframe');
    if (!clickxInput || !clickyInput || !iframe) {
        return;
    }

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.source !== iframe.contentWindow) {
            return;
        }
        if (!event.data || event.data.type !== 'omero-hotspot-click') {
            return;
        }
        clickxInput.value = event.data.x;
        clickyInput.value = event.data.y;

        const responseForm = document.getElementById('responseform');
        if (responseForm) {
            FormChangeChecker.markFormAsDirty(responseForm);
        }
    });
};
