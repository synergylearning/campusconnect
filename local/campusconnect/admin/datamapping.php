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
 * Settings page for campus connect
 *
 * @package    admin_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/datamapping.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectdatamapping');

require_login();
require_capability('moodle/site:config', context_system::instance());

$type = optional_param('type', 'import', PARAM_TEXT);
if ($type == 'export') {
    include_once($CFG->dirroot.'/local/campusconnect/admin/mapping/export.php');
} else {
    include_once($CFG->dirroot.'/local/campusconnect/admin/mapping/import.php');
}

echo $OUTPUT->footer();