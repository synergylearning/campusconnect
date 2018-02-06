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
 * Form for mapping personidtypes onto Moodle user fields, for course_members imports.
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\member_personid;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir.'/formslib.php');

class campusconnect_personidmapping_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'personidmappingheader', get_string('personidmapping', 'local_campusconnect'));

        $mform->addElement('html', html_writer::tag('p', get_string('personidmappingintro', 'local_campusconnect')));

        $fieldopts = member_personid::get_possible_user_fields();
        $fieldopts = array_merge(array('' => '-'), array_combine($fieldopts, $fieldopts));
        $mform->addElement('html', '<table class="userdatamappingtable">');
        $mform->addElement('html', '<thead><th>'.get_string('ecs', 'local_campusconnect').
                                 '</th><th>'.get_string('moodle', 'local_campusconnect').'</th></thead>');
        foreach (member_personid::$valididtypes as $fieldname) {
            $mform->addElement('html', '<tr><td>');
            $mform->addElement('html', '<span class="indentfield">'.$fieldname.'</span>');
            $mform->addElement('html', '</td><td>');
            $mform->addElement('select', "userfieldmapping[{$fieldname}]", '', $fieldopts);
            $mform->addElement('html', '</td></tr>');
        }
        $mform->addElement('html', '</table>');

        $this->add_action_buttons(false);
    }
}