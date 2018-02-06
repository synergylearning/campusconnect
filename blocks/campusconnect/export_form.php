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
 * Form for editing export settings for CampusConnect block
 *
 * @package    block_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\export;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/lib/formslib.php');

/**
 * Class block_campusconnect_export_form
 */
class block_campusconnect_export_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        /** @var export $export */
        $export = $this->_customdata;

        $parts = $export->list_participants();
        if (empty($parts)) {
            $mform->addElement('hidden', 'enableexport', false);
            $mform->addElement('static', 'noparticipants', '', get_string('noexportparticipants', 'block_campusconnect'));
        } else {
            $mform->addElement('selectyesno', 'enableexport', get_string('exportcourse', 'block_campusconnect'));
            $mform->setDefault('enableexport', $export->is_exported());

            foreach ($parts as $identifier => $part) {
                $elname = 'part_'.$identifier;
                $mform->addElement('advcheckbox', $elname, '', s($part->get_displayname()));
                $mform->disabledIf($elname, 'enableexport', 'eq', 0);
                $mform->setDefault($elname, $part->is_exported());
            }
        }
        $mform->addElement('hidden', 'courseid', $export->get_courseid());
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons();
    }
}