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
 * ECS settings page for campus connect
 *
 * @package    admin_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\ecssettings;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir."/formslib.php");

class campusconnect_ecs_form extends moodleform {

    public function definition() {

        $roles = role_fix_names(get_all_roles(), context_system::instance(), ROLENAME_ORIGINAL);
        $allowedroleids = get_roles_for_contextlevels(CONTEXT_COURSE);
        $optroles = array();
        foreach ($roles as $role) {
            if (in_array($role->id, $allowedroleids)) {
                $optroles[$role->shortname] = $role->localname;
            }
        }

        $strrequired = get_string('required');

        $mform = $this->_form;

        $mform->addElement('hidden', 'id', null);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'connectionsettings', get_string('connectionsettings', 'local_campusconnect'));

        $mform->addElement('selectyesno', 'enabled', get_string('ecsenabled', 'local_campusconnect'));
        $mform->addElement('text', 'name', get_string('name', 'local_campusconnect'));
        $mform->addRule('name', $strrequired, 'required', null, 'client');
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('text', 'url', get_string('url', 'local_campusconnect'), array('size' => 50));
        $mform->addRule('url', $strrequired, 'required', null, 'client');
        $mform->setType('url', PARAM_TEXT);
        $mform->addElement('static', 'urldesc', '', get_string('urldesc', 'local_campusconnect'));

        $mform->addElement('select', 'protocol', get_string('protocol', 'local_campusconnect'), array(
            'http' => 'HTTP', 'https' => 'HTTPS'
        ));
        $mform->addRule('protocol', $strrequired, 'required', null, 'client');

        $mform->addElement('text', 'port', get_string('port', 'local_campusconnect'));
        $mform->setType('port', PARAM_INT);

        $auth = array(
            ecssettings::AUTH_NONE => get_string('directconnection', 'local_campusconnect'),
            ecssettings::AUTH_CERTIFICATE => get_string('certificatebase', 'local_campusconnect'),
            ecssettings::AUTH_HTTP => get_string('usernamepassword', 'local_campusconnect')
        );
        $mform->addElement('select', 'auth', get_string('authenticationtype', 'local_campusconnect'), $auth);

        $mform->addElement('text', 'certpath', get_string('clientcertificate', 'local_campusconnect'), array('size' => 70));
        $mform->disabledIf('certpath', 'auth', 'neq', ecssettings::AUTH_CERTIFICATE);
        $mform->setType('certpath', PARAM_PATH);

        $mform->addElement('text', 'keypath', get_string('certificatekey', 'local_campusconnect'), array('size' => 70));
        $mform->disabledIf('keypath', 'auth', 'neq', ecssettings::AUTH_CERTIFICATE);
        $mform->setType('keypath', PARAM_PATH);

        $mform->addElement('password', 'keypass', get_string('keypassword', 'local_campusconnect'));
        $mform->disabledIf('keypass', 'auth', 'neq', ecssettings::AUTH_CERTIFICATE);
        $mform->setType('keypass', PARAM_RAW);

        $mform->addElement('text', 'cacertpath', get_string('cacertificate', 'local_campusconnect'), array('size' => 70));
        $mform->disabledIf('cacertpath', 'auth', 'neq', ecssettings::AUTH_CERTIFICATE);
        $mform->setType('cacertpath', PARAM_PATH);

        $mform->addElement('text', 'httpuser', get_string('username', 'local_campusconnect'));
        $mform->disabledIf('httpuser', 'auth', 'neq', ecssettings::AUTH_HTTP);
        $mform->setType('httpuser', PARAM_TEXT);

        $mform->addElement('text', 'httppass', get_string('password', 'local_campusconnect'));
        $mform->disabledIf('httppass', 'auth', 'neq', ecssettings::AUTH_HTTP);
        $mform->setType('httppass', PARAM_RAW);

        $mform->addElement('text', 'ecsauth', get_string('ecsauth', 'local_campusconnect'));
        $mform->disabledIf('ecsauth', 'auth', 'neq', ecssettings::AUTH_NONE);
        $mform->setType('ecsauth', PARAM_TEXT);

        $mform->addElement('header', 'localsettings', get_string('localsettings', 'local_campusconnect'));

        $selectarray = array();
        $selectarray[] = $mform->createElement('select', 'pollingtimemin', '', range(0, 59));
        $selectarray[] = $mform->createElement('static', 'pollingmins', '', get_string('minutes', 'local_campusconnect'));
        $selectarray[] = $mform->createElement('select', 'pollingtimesec', '', range(0, 59));
        $selectarray[] = $mform->createElement('static', 'pollingsecs', '', get_string('seconds', 'local_campusconnect'));
        $mform->addGroup($selectarray, 'pollingtime', get_string('pollingtime', 'local_campusconnect'), array(' '), false);
        $mform->addHelpButton('pollingtime', 'pollingtime', 'local_campusconnect');

        $mform->addElement('text', 'importcategory', get_string('categoryid', 'local_campusconnect'));
        $mform->addElement('static', 'categoryiddesc', '', get_string('categoryiddesc', 'local_campusconnect'));
        $mform->addRule('importcategory', $strrequired, 'required', null, 'client');
        $mform->setType('importcategory', PARAM_INT);

        $mform->addElement('header', 'useraccountsettings', get_string('useraccountsettings', 'local_campusconnect'));

        $mform->addElement('select', 'importrole', get_string('roleassignments', 'local_campusconnect'), $optroles);
        $mform->setDefault('importrole', 'student');
        $mform->addElement('select', 'importperiod', get_string('activationperiod', 'local_campusconnect'), range(0, 36));
        $mform->addElement('static', 'activationmonths', '', get_string('months', 'local_campusconnect'));
        $mform->setDefault('importperiod', '10');

        $mform->addElement('header', 'notifications', get_string('notifications', 'local_campusconnect'));

        $mform->addElement('text', 'notifyusers', get_string('notifcationaboutecsusers', 'local_campusconnect'),
                           array('size' => 50));
        $mform->addElement('static', 'usernotdesc', '', get_string('usernotificationdesc', 'local_campusconnect'));
        $mform->setType('notifyusers', PARAM_RAW);

        $mform->addElement('text', 'notifycontent', get_string('notificationaboutnewecontent', 'local_campusconnect'),
                           array('size' => 50));
        $mform->addElement('static', 'contentnotdesc', '', get_string('contentnotificationdesc', 'local_campusconnect'));
        $mform->setType('notifycontent', PARAM_RAW);

        $mform->addElement('text', 'notifycourses', get_string('notificationaboutapprovedcourses', 'local_campusconnect'),
                           array('size' => 50));
        $mform->addElement('static', 'coursenotdesc', '', get_string('coursenotificationdesc', 'local_campusconnect'));
        $mform->setType('notifycourses', PARAM_RAW);

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['auth'] == ecssettings::AUTH_CERTIFICATE) {
            if (empty($data['certpath'])) {
                $errors['certpath'] = get_string('required');
            }
            if (empty($data['keypath'])) {
                $errors['keypath'] = get_string('required');
            }
            if (empty($data['keypass'])) {
                $errors['keypass'] = get_string('required');
            }
            if (empty($data['cacertpath'])) {
                $errors['cacertpath'] = get_string('required');
            }
        }
        if ($data['auth'] == ecssettings::AUTH_HTTP) {
            if (empty($data['httpuser'])) {
                $errors['httpuser'] = get_string('required');
            }
            if (empty($data['httppass'])) {
                $errors['httppass'] = get_string('required');
            }
        }

        if (!is_numeric($data['importcategory'])) {
            $errors['importcategory'] = get_string('mustbevalidcategory', 'local_campusconnect');
        }

        return $errors;
    }
}
