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
 * Form for general directory tree settings
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\directorytree;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir.'/formslib.php');

class campusconnect_directorymapping_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        /** @var $dirtree directorytree */
        $dirtree = $this->_customdata['dirtree'];

        $statuses = array(
            directorytree::MODE_PENDING => get_string('modepending', 'local_campusconnect'),
            directorytree::MODE_WHOLE => get_string('modewhole', 'local_campusconnect'),
            directorytree::MODE_MANUAL => get_string('modemanual', 'local_campusconnect'),
            directorytree::MODE_DELETED => get_string('modedeleted', 'local_campusconnect')
        );
        $mode = $dirtree->get_mode();

        $mform->addElement('header', 'general', get_string('directorytreesettings', 'local_campusconnect'));

        $mform->addElement('static', 'staticrootid', get_string('cmsrootid', 'local_campusconnect'), $dirtree->get_root_id());
        $mform->addElement('static', 'staticstatus', get_string('treestatus', 'local_campusconnect'), $statuses[$mode]);

        $mform->addElement('selectyesno', 'takeovertitle', get_string('takeovertitle', 'local_campusconnect'));
        $mform->setDefault('takeovertitle', $dirtree->should_take_over_title());

        $mform->addElement('selectyesno', 'takeoverposition', get_string('takeoverposition', 'local_campusconnect'));
        $mform->setDefault('takeoverposition', $dirtree->should_take_over_position());

        $mform->addElement('selectyesno', 'takeoverallocation', get_string('takeoverallocation', 'local_campusconnect'));
        $mform->setDefault('takeoverallocation', $dirtree->should_take_over_allocation());

        $mform->addElement('hidden', 'id', $dirtree->get_root_id());
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }
}