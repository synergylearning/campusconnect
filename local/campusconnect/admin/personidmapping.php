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
 * Mapping of personidtypes for course_member import.
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\member_personid;

require_once(dirname(__FILE__).'/../../../config.php');
global $CFG, $PAGE, $OUTPUT;
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/campusconnect/admin/personidmapping_form.php');

admin_externalpage_setup('campusconnectpersonidmapping');

// Load existing data.
$mappings = member_personid::get_mapping();

// Load form.
$form = new campusconnect_personidmapping_form();
$form->set_data(array('userfieldmapping' => $mappings));

if ($data = $form->get_data()) {
    member_personid::set_mapping($data->userfieldmapping);
    redirect($PAGE->url);
}

// Output starts here.
echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();