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
 * Question-edit-form side of the multi-region hotspot handoff - a sibling
 * of qtype_omerohotspot/editform.js, not a mode of it. Builds/reloads a
 * live preview iframe pointing at local_omeroembed's own proxy.php (in its
 * "qtype multi authoring" mode - see that plugin's own
 * inject_hotspot_multi_edit_form_script()), and receives the whole current
 * array of regions back via postMessage from
 * js/hotspot-multi-qtype-author.js running inside it - written straight
 * into this form's own hidden 'geometry' field, exactly like any other
 * form field, only ever persisted when the whole question is saved.
 *
 * Uses distinctly namespaced message types (omero-hotspot-multi-*) rather
 * than reusing qtype_omerohotspot's own unnamespaced ones - deliberate,
 * since the payload shape differs (array vs. object) and a same-origin
 * listener receiving the wrong shape could corrupt state if both qtypes'
 * scripts were ever present in the same tab.
 *
 * @module     qtype_omerohotspotmulti/editform
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
    loadButton.textContent = await getString('loadslide', 'qtype_omerohotspotmulti');
    wrap.parentNode.insertBefore(loadButton, wrap);

    /**
     * (Re)points the preview iframe at the current subject/image/dataset
     * field values - same reasoning qtype_omerohotspot/editform.js's own
     * loadPreview() gives.
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
        url.searchParams.set('enablehotspotmulti', '1');
        url.searchParams.set('hotspotmode', 'qtypemulti');

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
        if (event.data && event.data.type === 'omero-hotspot-multi-author-ready') {
            // Handshake: the iframe has no way to know an existing
            // question's already-saved regions on its own - same
            // reasoning qtype_omerohotspot/editform.js's own handshake
            // gives.
            let existing = null;
            if (geometryEl && geometryEl.value) {
                try {
                    existing = JSON.parse(geometryEl.value);
                } catch (e) {
                    existing = null;
                }
            }
            iframe.contentWindow.postMessage(
                {type: 'omero-hotspot-multi-load-geometry', geometry: existing},
                window.location.origin
            );
        } else if (event.data && event.data.type === 'omero-hotspot-multi-geometry') {
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
