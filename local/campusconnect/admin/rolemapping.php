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
global $CFG, $DB, $PAGE, $OUTPUT;
require_once($CFG->dirroot.'/local/campusconnect/admin/rolemapping_form.php');

require_once($CFG->libdir.'/adminlib.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/rolemapping.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectrolemapping');

// Load existing data.
$mappings = $DB->get_records_menu('local_campusconnect_rolemap', array(), 'ccrolename', 'ccrolename, moodleroleid');

// In case roles have been deleted.
$DB->execute("DELETE FROM {local_campusconnect_rolemap} WHERE moodleroleid NOT IN (SELECT id FROM {role})");

// Load form.
$form = new campusconnect_rolemapping_form();
if ($data = $form->get_data()) {
    if ($form->is_cancelled()) {
        redirect($CFG->wwwroot);
    }
    $newmappings = array();
    if (isset($data->mapping)) {
        foreach ($data->mapping as $newmapping) {
            if (!isset($newmapping['ccrolename']) || !isset($newmapping['moodleroleid'])) {
                continue;
            }
            $ccrolename = trim($newmapping['ccrolename']);
            $moodleroleid = $newmapping['moodleroleid'];
            if ($ccrolename == '' || !is_number($moodleroleid)) {
                continue;
            }
            $newmappings[$ccrolename] = $moodleroleid;
        }
    }

    // Deleted and changed.
    foreach ($mappings as $ccrolename => $moodleroleid) {
        if (isset($newmappings[$ccrolename])) {
            if ($newmappings[$ccrolename] == $moodleroleid) {
                continue;
            }
            $params = array(
                'moodleroleid' => $newmappings[$ccrolename],
                'ccrolename' => $ccrolename,
            );
            $DB->execute("UPDATE {local_campusconnect_rolemap} SET moodleroleid = :moodleroleid WHERE ccrolename = :ccrolename",
                         $params);
            $mappings[$ccrolename] = $newmappings[$ccrolename];
        } else {
            $DB->delete_records('local_campusconnect_rolemap', array('ccrolename' => $ccrolename));
            unset($mappings[$ccrolename]);
        }
    }

    // New.
    foreach ($newmappings as $ccrolename => $moodleroleid) {
        if (!isset($mappings[$ccrolename])) {
            $toinsert = (object)array(
                'ccrolename' => $ccrolename,
                'moodleroleid' => $moodleroleid,
            );
            $DB->insert_record('local_campusconnect_rolemap', $toinsert);
            $mappings[$ccrolename] = $moodleroleid;
        }
    }
    redirect($CFG->wwwroot.'/local/campusconnect/admin/rolemapping.php');
}

// Output starts here.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

echo $OUTPUT->heading(get_string('rolemapping', 'local_campusconnect'), 4);

$form->display();

echo $OUTPUT->footer();