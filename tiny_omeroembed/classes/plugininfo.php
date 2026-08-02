<?php
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

namespace tiny_omeroembed;

use context;
use editor_tiny\plugin;
use editor_tiny\plugin_with_buttons;
use editor_tiny\plugin_with_menuitems;

/**
 * Tiny OMERO Embed plugin for Moodle.
 *
 * Adds a toolbar/menu button that opens local_omeroembed's own author.php
 * (subject/image picker, live pan-zoom preview, view-link insertion) inside a
 * modal, and inserts the resulting embed HTML into the editor - see
 * amd/src/ui.js for the actual modal + postMessage handoff.
 *
 * @package    tiny_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugininfo extends plugin implements
    plugin_with_buttons,
    plugin_with_menuitems {

    public static function is_enabled(
        context $context,
        array $options,
        array $fpoptions,
        ?\editor_tiny\editor $editor = null
    ): bool {
        // Course-level access is still enforced for real by author.php itself
        // (moodle/course:manageactivities) - this only controls whether the
        // button/menu item is offered at all, same defense-in-depth pattern
        // local_omeroembed already uses elsewhere.
        return has_capability('tiny/omeroembed:embed', $context);
    }

    public static function get_available_buttons(): array {
        return [
            'tiny_omeroembed/omeroembed',
        ];
    }

    public static function get_available_menuitems(): array {
        return [
            'tiny_omeroembed/omeroembed',
        ];
    }
}
