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
 * Tiny OMERO Embed toolbar/menu registration.
 *
 * @module      tiny_omeroembed/commands
 * @copyright   2026 University of Glasgow MVLS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getImagePath} from 'editor_tiny/utils';
import {handleAction} from './ui';
import {getString} from 'core/str';
import {
    component,
    buttonName,
    icon,
} from './common';

/**
 * Fetches the plugin's icon.svg as raw markup, rather than going through
 * editor_tiny/utils' getButtonImage() - that helper wraps the icon in
 * <image href="..."> (see editor_tiny/toolbar_button.mustache), which loads
 * it as an isolated external resource with no CSS cascade to inherit
 * "color" from. That's fine for icons with a hardcoded fill, but it means
 * fill="currentColor" can never pick up the toolbar's actual (light/dark)
 * text colour the way TinyMCE's own built-in icons do, since those are
 * registered from literal inline SVG strings. Fetching the raw text and
 * handing THAT to addIcon() instead makes our icon genuinely inline too,
 * so it recolours correctly alongside the rest of the toolbar - confirmed
 * live: this is what fixed a reported "icon shows grey instead of white
 * in dark mode" issue (the <image href> version could only ever show its
 * one hardcoded colour, never adapt).
 *
 * @param {string} identifier The icon identifier, e.g. 'icon'.
 * @param {string} iconcomponent The component the icon belongs to.
 * @returns {Promise<string>} The icon's raw SVG markup.
 */
const getIconMarkup = async(identifier, iconcomponent) => {
    const url = await getImagePath(identifier, iconcomponent);
    const response = await fetch(url);
    return response.text();
};

export const getSetup = async() => {
    const [
        buttonText,
        buttonMarkup,
    ] = await Promise.all([
        getString('buttontitle', component),
        getIconMarkup('icon', component),
    ]);

    return (editor) => {
        // Moodle's add_intro_editor() (core_form\...\editor.php) names every
        // activity/resource's "Description" field under General literally
        // "introeditor" - a small summary/blurb field, not a real content
        // area - confirmed live (id="id_introeditor" on mod_page's own edit
        // form, distinct from "id_page", its actual content field).
        // is_enabled() (see classes/plugininfo.php) can't distinguish these:
        // Moodle's core editor_tiny\editor::use_editor() never forwards the
        // element id into the $options array that reaches is_enabled(), only
        // TinyMCE's own per-instance setup callback (here) genuinely has it,
        // via the real editor object - so the exclusion has to live here,
        // not server-side. Reported directly by the user: showing the
        // button on a field that was never meant to hold a whole slide
        // embed was just confusing, not a real, usable option there.
        if (editor.id === 'id_introeditor' || editor.id.endsWith('_introeditor')) {
            return;
        }

        editor.ui.registry.addIcon(icon, buttonMarkup);

        editor.ui.registry.addButton(buttonName, {
            icon,
            tooltip: buttonText,
            onAction: () => handleAction(editor),
        });

        editor.ui.registry.addMenuItem(buttonName, {
            icon,
            text: buttonText,
            onAction: () => handleAction(editor),
        });
    };
};
