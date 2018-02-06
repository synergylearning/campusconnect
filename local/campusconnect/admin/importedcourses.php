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

use local_campusconnect\courselink;
use local_campusconnect\ecssettings;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/importedcourses.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectimportedcourses');

// Set up table contents.
$table = new html_table();
$table->attributes = array('width' => '100%');
$table->head = array(
    get_string('title', 'local_campusconnect'),
    get_string('links', 'local_campusconnect'),
    get_string('importedfrom', 'local_campusconnect'),
    get_string('metadata', 'local_campusconnect')
);

$table->data = array();
$table->attributes = array('class' => 'generaltable campusconnect_imported');

$ecslist = ecssettings::list_ecs();
foreach ($ecslist as $ecsid => $ecsname) {
    $links = courselink::list_links($ecsid);
    foreach ($links as $link) {
        $row = array(
            format_string($link->get_title()),
            $link->get_link(),
            format_string($link->get_participantname()),
            format_text($link->get_summary(), FORMAT_HTML)
        );
        $table->data[] = $row;
    }
}

// Output starts here.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

echo $OUTPUT->heading(get_string('importedcourses', 'local_campusconnect'), 4);

if (empty($table->data)) {
    echo html_writer::tag('p', get_string('noimportedcourses', 'local_campusconnect'));
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
