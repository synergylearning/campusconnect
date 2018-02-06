<?php
// This file is part of the CampusConnect plugin for Moodle - http://moodle.org/
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
 * Code for editing export settings for CampusConnect block
 *
 * @package    block_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\export;

require_once(dirname(__FILE__).'/../../config.php');
global $CFG, $DB, $SITE, $PAGE, $OUTPUT;
require_once($CFG->dirroot.'/blocks/campusconnect/export_form.php');

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
if ($course->id == $SITE->id) {
    throw new moodle_exception('notsiteid', 'block_campusconnect');
}

$PAGE->set_url(new moodle_url('/blocks/campusconnect/export.php', array('courseid' => $course->id)));
require_login($course);

$context = context_course::instance($course->id);
require_capability('moodle/course:update', $context);

$export = new export($course->id);
$form = new block_campusconnect_export_form(null, $export);

$redir = new moodle_url('/course/view.php', array('id' => $course->id));
if ($form->is_cancelled()) {
    redirect($redir);
}

if ($data = $form->get_data()) {
    if (!$data->enableexport) {
        $export->clear_exports();
    } else {
        foreach ($data as $name => $value) {
            if (substr_compare($name, 'part_', 0, 5) === 0) {
                $identifier = substr($name, 5);
                $export->set_export($identifier, $value);
            }
        }
    }
    redirect($redir);
}

$strexport = get_string('exportcourse', 'block_campusconnect');
$PAGE->set_title("{$course->shortname}: $strexport");
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($strexport);

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();