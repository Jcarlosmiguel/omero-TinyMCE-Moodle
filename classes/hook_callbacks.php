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

namespace local_omeroembed;

use core\hook\navigation\primary_extend;

/**
 * Hook listeners registered in db/hooks.php.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Adds an "OMERO slide embed settings" link to the site's primary
     * navigation bar (Boost's actual top nav, not the legacy navigation tree -
     * see lib.php's own comment on why this couldn't just be a plain
     * extend_navigation() callback), for anyone with
     * local/omeroembed:managesettings - Managers by default, or anyone else
     * explicitly granted it, see db/access.php. Deliberately not the Site
     * administration tree, since that's only ever visible to users who also
     * have moodle/site:config, which this capability is independent of.
     *
     * @param primary_extend $hook
     * @return void
     */
    public static function extend_primary_navigation(primary_extend $hook): void {
        $context = \context_system::instance();
        if (!has_capability('local/omeroembed:managesettings', $context)) {
            return;
        }

        $hook->get_primaryview()->add(
            get_string('managetitle', 'local_omeroembed'),
            new \moodle_url('/local/omeroembed/manage.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_omeroembed_manage',
            new \pix_icon('t/preferences', '')
        );
    }
}
