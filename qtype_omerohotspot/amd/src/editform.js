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
 * Question-edit-form side of the hotspot region handoff. Builds/reloads a
 * live preview iframe pointing at local_omeroembed's own proxy.php (in its
 * "qtype authoring" mode - see that plugin's inject_hotspot_edit_form_script()),
 * and receives the drawn region back via postMessage from
 * js/hotspot-qtype-author.js running inside it - written straight into
 * this form's own hidden 'geometry' field, exactly like any other form
 * field, only ever persisted when the whole question is saved.
 *
 * @module     qtype_omerohotspot/editform
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import {getString} from 'core/str';

/**
 * @param {String} wrapId id of the empty <div> the edit form left for the preview.
 */
export const init = async(wrapId) => {
    const wrap = document.getElementById(wrapId);
    if (!wrap) {
        return;
    }
    const courseid = wrap.dataset.courseid;

    const subjectEl = document.getElementById('id_subjectid');
    const imageEl = document.getElementById('id_imageid');
    const datasetEl = document.getElementById('id_datasetid');
    const geometryEl = document.getElementById('id_geometry');

    let iframe = null;

    const loadButton = document.createElement('button');
    loadButton.type = 'button';
    loadButton.className = 'btn btn-secondary mb-2';
    loadButton.textContent = await getString('loadslide', 'qtype_omerohotspot');
    wrap.parentNode.insertBefore(loadButton, wrap);

    /**
     * (Re)points the preview iframe at the current subject/image/dataset
     * field values - a fresh load, same as author.php's own "Load slide"
     * button, since changing subject/image genuinely needs a new page in
     * the iframe, not something a live view can update in place.
     */
    const loadPreview = () => {
        const subjectid = subjectEl ? subjectEl.value : '';
        const imageid = imageEl ? imageEl.value : '';
        const datasetid = datasetEl ? datasetEl.value : '';
        if (!subjectid || (!imageid && !datasetid)) {
            return;
        }

        const url = new URL(
            `${Config.wwwroot}/local/omeroembed/proxy.php/${courseid}/${subjectid}`,
            window.location.href
        );
        if (imageid) {
            url.searchParams.set('images', imageid);
        }
        if (datasetid) {
            url.searchParams.set('dataset', datasetid);
        }
        url.searchParams.set('authoring', '1');
        url.searchParams.set('enablehotspot', '1');
        url.searchParams.set('hotspotmode', 'qtype');

        iframe = document.createElement('iframe');
        iframe.style.cssText = 'width:100%; height:100%; border:0;';
        iframe.src = url.toString();
        wrap.innerHTML = '';
        wrap.appendChild(iframe);
    };

    loadButton.addEventListener('click', loadPreview);

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || !iframe || event.source !== iframe.contentWindow) {
            return;
        }
        if (event.data && event.data.type === 'omero-hotspot-author-ready') {
            // Handshake: the iframe has no way to know an existing
            // question's already-saved geometry on its own (proxy.php
            // serves the same generic viewer regardless of which question
            // is being edited) - so it announces readiness and this
            // responds with whatever this form's own hidden field already
            // holds (blank for a brand new question).
            let existing = null;
            if (geometryEl && geometryEl.value) {
                try {
                    existing = JSON.parse(geometryEl.value);
                } catch (e) {
                    existing = null;
                }
            }
            iframe.contentWindow.postMessage({type: 'omero-hotspot-load-geometry', geometry: existing}, window.location.origin);
        } else if (event.data && event.data.type === 'omero-hotspot-geometry') {
            if (geometryEl) {
                geometryEl.value = event.data.geometry ? JSON.stringify(event.data.geometry) : '';
            }
        }
    });

    // Re-editing an existing question already has subject/image values
    // filled in from data_preprocessing() - load the preview straight
    // away rather than making the teacher click twice.
    if (subjectEl && subjectEl.value && ((imageEl && imageEl.value) || (datasetEl && datasetEl.value))) {
        loadPreview();
    }
};
