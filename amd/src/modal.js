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
 * OMERO Embed modal for Tiny - body is just an iframe onto author.php.
 *
 * @module      tiny_omeroembed/modal
 * @copyright   2026 University of Glasgow MVLS
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';

export default class OmeroEmbedModal extends Modal {
    static TYPE = 'tiny_omeroembed/modal';
    static TEMPLATE = 'tiny_omeroembed/modal';

    configure(modalConfig) {
        modalConfig.large = true;
        modalConfig.show = true;
        modalConfig.removeOnClose = true;

        super.configure(modalConfig);

        // "large" only gets us Bootstrap's modal-lg, capped at 800px - the
        // same as this tool's own default embed Width, and less once the
        // modal's own padding/border are subtracted. That squeezes the live
        // preview narrower than the teacher's configured Width, so a
        // pan/zoom position saved while authoring doesn't frame the slide
        // the same way once the real embed renders at its full (wider)
        // size elsewhere - confirmed as the actual cause of a reported
        // "positioning is a bit off" issue. Widen well past any typical
        // configured width rather than hard-coding one specific pixel value.
        this.getModal().css('max-width', '95vw');
    }
}

OmeroEmbedModal.registerModalType();
