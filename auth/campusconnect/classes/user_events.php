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
 * Handle user events
 *
 * @package   auth_campusconnect
 * @copyright 2016 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_campusconnect;

use local_campusconnect\enrolment;
use local_campusconnect\log;

defined('MOODLE_INTERNAL') || die();

class user_events {
    /**
     * Handle user enrolment events - update 'last enroled' value + notify ECS (if needed).
     *
     * @param \core\event\user_enrolment_created $event
     * @return bool
     */
    public static function user_enrolled(\core\event\user_enrolment_created $event) {
        global $DB, $USER;

        if ($event->relateduserid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', array('id' => $event->relateduserid));
        }

        if ($user->auth !== 'campusconnect') {
            return true; // Only interested in users who authenticated via Campus Connect.
        }

        if (!$authrec = $DB->get_record('auth_campusconnect', array('username' => $user->username))) {
            log::add("auth_campusconnect - user '{$user->username}' missing record in auth_campusconnect database table");
            return true; // I don't think this should ever happen, but avoid throwing a fatal error.
        }

        $upd = (object)array(
            'id' => $authrec->id,
            'lastenroled' => $event->timecreated,
        );
        $DB->update_record('auth_campusconnect', $upd);

        enrolment::set_status($event->courseid, $user, enrolment::STATUS_ACTIVE);

        return true;
    }

    /**
     * Handle unenrolment events
     *
     * @param \core\event\user_enrolment_deleted $event
     * @return bool
     */
    public static function user_unenrolled(\core\event\user_enrolment_deleted $event) {
        global $USER, $DB;

        if ($event->relateduserid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', array('id' => $event->relateduserid));
        }

        if ($user->auth != 'campusconnect') {
            return true; // Only interested in users who authenticated via Campus Connect.
        }

        enrolment::set_status($event->courseid, $user, enrolment::STATUS_UNSUBSCRIBED);

        return true;
    }

    /**
     * Handle user updated events and check for users becoming suspended (or unsuspended).
     *
     * @param \core\event\user_updated $event
     * @return bool
     */
    public static function user_updated(\core\event\user_updated $event) {
        global $USER, $DB;

        if ($event->objectid == $USER->id) {
            $user = $USER;
        } else {
            $user = $DB->get_record('user', ['id' => $event->objectid]);
        }

        if ($user->auth != 'campusconnect') {
            return true;  // Only interested in users who authenticated via Campus Connect.
        }

        $oldsuspended = $DB->get_field('auth_campusconnect', 'suspended', array('username' => $user->username));
        if ($oldsuspended && !$user->suspended) {
            $status = enrolment::STATUS_ACTIVE; // User no longer suspended - mark all enrolments as active.
        } else if (!$oldsuspended && $user->suspended) {
            $status = enrolment::STATUS_INACTIVE; // User is now suspended - mark all enrolments as inactive.
        } else {
            return true; // No change in suspended status.
        }
        $DB->set_field('auth_campusconnect', 'suspended', $user->suspended, array('username' => $user->username));

        // Update status for all courses the user is enroled in.
        foreach (enrol_get_all_users_courses($user->id) as $course) {
            enrolment::set_status($course->id, $user, $status);
        }

        return true;
    }
}