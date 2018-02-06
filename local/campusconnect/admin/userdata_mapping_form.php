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
 * Form for mapping user data to/from courselink authentication.
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\courselink;
use local_campusconnect\participantsettings;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir.'/formslib.php');

class campusconnect_userdata_mapping_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'ecsid');
        $mform->setType('ecsid', PARAM_INT);
        $mform->addElement('hidden', 'mid');
        $mform->setType('mid', PARAM_INT);

        if ($this->_customdata['showexport']) {
            $mform->addElement('header', 'exportheader', get_string('exportuserdata', 'local_campusconnect'));
            $mform->addElement('html', html_writer::tag('p', get_string('exportuserdatainfo', 'local_campusconnect')));

            $exportopts = participantsettings::get_possible_export_fields();
            $exportopts = array_merge(array('' => '-'), array_combine($exportopts, $exportopts));
            $mform->addElement('html', '<table class="userdatamappingtable">');
            $mform->addElement('html', '<thead><th>'.get_string('ecs', 'local_campusconnect').
                                     '</th><th>'.get_string('moodle', 'local_campusconnect').
                                     '</th><th>'.get_string('id', 'local_campusconnect').'</th></thead>');
            foreach (courselink::$validexportmappingfields as $fieldname) {
                $mform->addElement('html', '<tr><td>');
                $mform->addElement('checkbox', "exportfields[{$fieldname}]", '', $fieldname,
                                   array('value' => $fieldname));
                $mform->addElement('html', '</td><td>');
                $mform->addElement('select', "exportfieldmapping[{$fieldname}]", '', $exportopts);
                $mform->addElement('html', '</td><td>');
                if (in_array($fieldname, courselink::$validpersontypes)) {
                    $mform->addElement('radio', 'personuidtype', '', '', $fieldname);
                }
                $mform->addElement('html', '</td></tr>');
            }
            $mform->addElement('html', '</table>');
        }

        if ($this->_customdata['showimport']) {
            $mform->addElement('header', 'importheader', get_string('importuserdata', 'local_campusconnect'));
            $mform->addElement('html', html_writer::tag('p', get_string('importuserdatainfo', 'local_campusconnect')));

            $importopts = participantsettings::get_possible_import_fields();
            $importopts = array_merge(array('' => '-'), array_combine($importopts, $importopts));
            $mform->addElement('html', '<table class="userdatamappingtable">');
            $mform->addElement('html', '<thead><th>'.get_string('ecs', 'local_campusconnect').
                                     '</th><th>'.get_string('moodle', 'local_campusconnect').'</th></thead>');
            foreach (courselink::$validimportmappingfields as $fieldname) {
                $mform->addElement('html', '<tr><td>');
                $mform->addElement('html', '<span class="indentfield">'.$fieldname.'</span>');
                $mform->addElement('html', '</td><td>');
                $mform->addElement('select', "importfieldmapping[{$fieldname}]", '', $importopts);
                $mform->addElement('html', '</td></tr>');
            }
            $mform->addElement('html', '</table>');
        }

        $this->add_action_buttons(false);
    }
}