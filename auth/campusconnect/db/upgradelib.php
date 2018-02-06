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
 * Functions for use during upgrades
 *
 * @package   auth_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function auth_campusconnect_populate_lastenroled() {
    global $DB;
    echo html_writer::tag('p', "Updating all 'last enroled' dates - this may take a while to run ...");

    $sql = "SELECT ac.id, MAX(l.time) AS lastenroled
              FROM {auth_campusconnect} ac
              JOIN {user} u ON u.username = ac.username
              JOIN {log} l ON l.userid = u.id AND l.action = 'enrol'
             GROUP BY ac.id";
    $rs = $DB->get_recordset_sql($sql);
    $i = 0;
    foreach ($rs as $authrecord) {
        if (($i % 30) == 0) {
            echo '.';
        }
        if ($i && (($i % 3000) == 0)) {
            echo '<br />';
        }
        $i++;

        $upd = (object)array(
            'id' => $authrecord->id,
            'lastenroled' => $authrecord->lastenroled,
        );
        $DB->update_record('auth_campusconnect', $upd);
    }
}