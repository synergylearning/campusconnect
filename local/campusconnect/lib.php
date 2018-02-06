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
 * Library functions for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\connect;
use local_campusconnect\connect_exception;
use local_campusconnect\course;
use local_campusconnect\course_url;
use local_campusconnect\courselink;
use local_campusconnect\directorytree;
use local_campusconnect\ecssettings;
use local_campusconnect\enrolment;
use local_campusconnect\export;
use local_campusconnect\membership;
use local_campusconnect\notification;
use local_campusconnect\participantsettings;
use local_campusconnect\receivequeue;

defined('MOODLE_INTERNAL') || die();

function local_campusconnect_cron() {
    // Get updates from all ECS.
    $ecslist = ecssettings::list_ecs();
    foreach ($ecslist as $ecsid => $name) {
        $ecssettings = new ecssettings($ecsid);

        if ($ecssettings->time_for_cron()) {
            mtrace("Checking for updates on ECS server '".$ecssettings->get_name()."'");
            $connect = new connect($ecssettings);
            $queue = new receivequeue();

            try {
                $queue->update_from_ecs($connect);
                $queue->process_queue($ecssettings);
            } catch (connect_exception $e) {
                local_campusconnect_ecs_error_notification($ecssettings, $e->getMessage());
            }

            mtrace("Sending updates to ECS server '".$ecssettings->get_name()."'");
            try {
                export::update_ecs($connect);
                course_url::update_ecs($connect);
                enrolment::update_ecs($connect);
            } catch (connect_exception $e) {
                local_campusconnect_ecs_error_notification($ecssettings, $e->getMessage());
            }

            $cms = participantsettings::get_cms_participant();
            if ($cms && $cms->get_ecs_id() == $ecssettings->get_id()) {
                // If we are updating from the ECS with the CMS attached, then check the directory mappings (and sort order).
                directorytree::check_all_mappings();
            }

            mtrace("Emailing any necessary notifications for '".$ecssettings->get_name()."'");
            notification::send_notifications($ecssettings);

            $ecssettings->update_last_cron();
        }
    }
}

/**
 * Sends a message out to all admin users if there is an ECS connection problem
 * (message is 'from' the first admin user)
 * @param ecssettings $ecssettings
 * @param string $msg
 */
function local_campusconnect_ecs_error_notification(ecssettings $ecssettings, $msg) {
    mtrace("ECS connection error, sending notification to site admins - $msg");

    $admins = get_admins();
    $fromuser = reset($admins);

    $details = (object)array(
        'ecsname' => $ecssettings->get_name(),
        'ecsid' => $ecssettings->get_id(),
        'msg' => $msg
    );

    $eventdata = new stdClass();
    $eventdata->component = 'local_campusconnect';
    $eventdata->name = 'ecserror';
    $eventdata->userfrom = $fromuser;
    $eventdata->subject = get_string('ecserror_subject', 'local_campusconnect');
    $eventdata->fullmessage = get_string('ecserror_body', 'local_campusconnect', $details);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = $eventdata->fullmessage;
    $eventdata->fullmessagehtml = str_replace("\n", '<br/>', $eventdata->fullmessagehtml);
    $eventdata->smallmessage = '';

    foreach ($admins as $adminuser) {
        $eventdata->userto = $adminuser;
        message_send($eventdata);
    }
}

/**
 * Refresh all imports / exports for the given ECS
 * @param ecssettings $ecssettings
 * @param bool $output
 * @return stdClass
 */
function local_campusconnect_refresh_ecs(ecssettings $ecssettings, $output = false) {
    if (!$ecssettings->is_enabled()) {
        return false;
    }

    $connect = new connect($ecssettings);

    // Work through the message queue before resyncing all data.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processmessages', 'local_campusconnect'));
    }
    $queue = new receivequeue();
    try {
        $queue->update_from_ecs($connect);
        $queue->process_queue($ecssettings);
    } catch (connect_exception $e) {
        local_campusconnect_ecs_error_notification($ecssettings, $e->getMessage());
    }

    $ret = new stdClass();

    // Resync all exported courses.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processexport', 'local_campusconnect'));
    }
    $ret->export = export::refresh_ecs($connect);

    // Resync all imported directory trees.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processdirtree', 'local_campusconnect'));
    }
    $ret->dirtree = directorytree::refresh_from_ecs($ecssettings);

    // Resync all imported courses.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processcourse', 'local_campusconnect'));
    }
    $ret->course = course::refresh_from_ecs($ecssettings);

    // Resync all exported course urls.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processcourseurl', 'local_campusconnect'));
    }
    $ret->courseurl = course_url::refresh_ecs($connect);

    // Resync all imported course memberships.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processmembership', 'local_campusconnect'));
    }
    $ret->membership = membership::refresh_from_ecs($ecssettings);

    // Resync all imported course links.
    if ($output) {
        echo html_writer::tag('p', get_string('refresh_processcourselinks', 'local_campusconnect'));
    }
    $ret->courselink = courselink::refresh_from_ecs($ecssettings);

    return $ret;
}
