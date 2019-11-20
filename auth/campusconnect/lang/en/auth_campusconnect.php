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
 * Strings for component 'auth_campusconnect', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package    campusconnect
 * @copyright  2012 Synergy Learning
 */

$string['auth_campusconnectdescription'] = 'Authenticates user from another participant by verifying ECS hash in the URL against the ECS server';
$string['usernamecantfindecs'] = 'Could not find ECS id in username';
$string['deletinguser'] = 'Deleting user';
$string['errorcreatinguser'] = 'Error creating user';
$string['newusernotifysubject'] = 'CampusConnect user created';
$string['newusernotifybody'] = 'A new user was created by SSO login from another CampusConnect participant: {$a->firstname} {$a->lastname}.';
$string['pluginname'] = 'CampusConnect';
$string['privacy:metadata:auth_campusconnect'] = 'Extra information stored about a user to enable CampusConnect login';
$string['privacy:metadata:auth_campusconnect:lastenroled'] = 'The timestamp for when the user last enroled in a course';
$string['privacy:metadata:auth_campusconnect:personid'] = 'Stores the UID by which the user is known on the remote system';
$string['privacy:metadata:auth_campusconnect:personidtype'] = 'The type of personid that was sent when the user was first authenticated.';
$string['privacy:metadata:auth_campusconnect:pids'] = 'Stores the ids of participants that this user has come from as ecsid_pid';
$string['privacy:metadata:auth_campusconnect:suspended'] = 'Record whether or not the user was suspended, so can spot changes when \'user_updated\' events occur.';
$string['privacy:metadata:auth_campusconnect:username'] = 'The generated username for this user';
$string['privacy:path:auth_campusconnect'] = 'CampusConnect authentication';
