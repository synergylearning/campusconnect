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

use local_campusconnect\export;
use local_campusconnect\participantsettings;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT, $DB;

require_once($CFG->libdir.'/adminlib.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/releasedcourses.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectreleasedcourses');

require_login();
require_capability('moodle/site:config', context_system::instance());

if (optional_param('refreshall', false, PARAM_BOOL)) {
    // Refresh all the exports (if requested).
    require_sesskey();
    $errors = export::refresh_all_ecs();
    if (empty($errors)) {
        redirect($PAGE->url);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->notification(implode('<br/>', $errors));
    echo $OUTPUT->continue_button($PAGE->url);
    echo $OUTPUT->footer();
    die();
}

// Get list of exported courses (and course details).
$courses = array();
$exports = export::list_all_exports();
if ($exports) {
    $courseids = array();
    foreach ($exports as $export) {
        $courseids[] = $export->get_courseid();
    }
    $courses = $DB->get_records_list('course', 'id', $courseids, 'id', 'id, fullname');
}

// Table headings.
$table = new html_table();
$table->head = array(
    get_string('coursename', 'local_campusconnect'),
    get_string('exportparticipants', 'local_campusconnect')
);
$table->attributes = array('style' => 'width: 90%;');
$table->size = array(
    '40%',
    '60%'
);

$strstatus = array(
    export::STATUS_CREATED => get_string('exportcreated', 'local_campusconnect'),
    export::STATUS_UPDATED => get_string('exportupdated', 'local_campusconnect'),
    export::STATUS_DELETED => get_string('exportdeleted', 'local_campusconnect'),
);

// Gather details for each exported course.
$table->data = array();
foreach ($exports as $export) {
    /** @var $export export */
    $coursename = format_string($courses[$export->get_courseid()]->fullname);
    $courseurl = new moodle_url('/course/view.php', array('id' => $export->get_courseid()));
    $courselink = html_writer::link($courseurl, $coursename);

    $part = array();
    $participants = $export->list_current_exports();
    if (empty($participants)) {
        continue;
    }
    foreach ($participants as $identifier => $participant) {
        /** @var $participant participantsettings */
        $partname = $participant->get_displayname();
        $status = $export->get_status($identifier);
        if ($status != export::STATUS_UPTODATE) {
            $partname .= ' ('.$strstatus[$status].')';
        }
        $part[] = $partname;
    }

    $row = array(
        $courselink,
        implode('<br/>', $part)
    );

    $table->data[] = $row;
}

$refreshurl = new moodle_url($PAGE->url, array('refreshall' => 1, 'sesskey' => sesskey()));

// Output exported details.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('releasedcourses', 'local_campusconnect'));

echo $OUTPUT->single_button($refreshurl, get_string('refreshexport', 'local_campusconnect'), 'POST');
echo html_writer::empty_tag('br');

if ($table->data) {
    echo html_writer::table($table);
} else {
    echo get_string('nocourseexport', 'local_campusconnect');
}

echo $OUTPUT->footer();