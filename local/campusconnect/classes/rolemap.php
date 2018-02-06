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

/**
 * Handle role mapping
 *
 * @package   local_campusconnect
 * @copyright 2016 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

defined('MOODLE_INTERNAL') || die();

class rolemap {
    /*
     * For a given CampusConnect role, return the id of its role mapping (or false when not found)
     * @param string $role
     */
    public static function get_roleid($role) {
        // Cache entire mapping.
        static $mapping = null;
        if (is_null($mapping)) {
            global $DB;
            $mapping = $DB->get_records_menu('local_campusconnect_rolemap', null, '', 'ccrolename, moodleroleid');
        }
        if (isset($mapping[$role])) {
            return $mapping[$role];
        }
        return false;
    }
}