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
 * Site admin settings for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\ecssettings;

defined('MOODLE_INTERNAL') || die();

global $CFG;

if ($hassiteconfig) {
    require_once($CFG->dirroot.'/local/campusconnect/lib.php');

    $ADMIN->add('root', new admin_category('campusconnect', get_string('pluginname', 'local_campusconnect')));

    try {
        $ecslist = ecssettings::list_ecs(false);
    } catch (Exception $e) {
        return; // Skip during install.
    }
    // Web service test clients DO NOT COMMIT : THE EXTERNAL WEB PAGE IS NOT AN ADMIN PAGE !!!!!
    /*
    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectsettings',
                                                        get_string('settings', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/campusconnect.php')));
    */

    $ADMIN->add('campusconnect', new admin_category('ecs', get_string('ecs', 'local_campusconnect')));

    $ADMIN->add('ecs', new admin_externalpage('allecs',
                                              get_string('allecs', 'local_campusconnect'),
                                              new moodle_url('/local/campusconnect/admin/allecs.php')));

    foreach ($ecslist as $ecsid => $ecsname) {
        $ADMIN->add('ecs', new admin_externalpage('ecs'.$ecsid,
                                                  $ecsname,
                                                  new moodle_url('/local/campusconnect/admin/ecs.php', array('id' => $ecsid))));
    }

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectparticipants',
                                                        get_string('participants', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/participants.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectuserdatamapping',
                                                        get_string('userdatamapping', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/userdatamapping.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectdatamapping',
                                                        get_string('ecsdatamapping', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/datamapping.php')));

    /*
    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectcategoryassignment',
                                                        get_string('assignmenttocategories', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/categoryassignment.php')));
    */

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectimportedcourses',
                                                        get_string('importedcourses', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/importedcourses.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectreleasedcourses',
                                                        get_string('releasedcourses', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/releasedcourses.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectdirectorymapping',
                                                        get_string('directorymapping', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/directorytree.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectcoursefiltering',
                                                        get_string('coursefiltering', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/coursefiltering.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectpersonidmapping',
                                                        get_string('personidmapping', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/personidmapping.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectrolemapping',
                                                        get_string('rolemapping', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/admin/rolemapping.php')));

    $ADMIN->add('campusconnect', new admin_externalpage('campusconnectviewlog',
                                                        get_string('viewlog', 'local_campusconnect'),
                                                        new moodle_url('/local/campusconnect/viewlog.php')));
}