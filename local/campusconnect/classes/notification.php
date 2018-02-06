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
 * Send out notifications to admin users about updates
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use html_writer;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Looks after CampusConnect email notifications and sends out the messages automatically.
 */
class notification {
    const MESSAGE_IMPORT_COURSELINK = 1;
    const MESSAGE_EXPORT_COURSELINK = 2;
    const MESSAGE_USER = 3;
    const MESSAGE_COURSE = 4;
    const MESSAGE_DIRTREE = 5;

    const TYPE_CREATE = 0;
    const TYPE_UPDATE = 1;
    const TYPE_DELETE = 2;
    const TYPE_ERROR = 3;

    public static $messagetypes = array(
        self::MESSAGE_IMPORT_COURSELINK, self::MESSAGE_EXPORT_COURSELINK,
        self::MESSAGE_USER, self::MESSAGE_COURSE, self::MESSAGE_DIRTREE
    );

    public static $messagesubtypes = array(self::TYPE_CREATE, self::TYPE_UPDATE, self::TYPE_DELETE, self::TYPE_ERROR);

    /**
     * Queue a new notification to be sent out via email
     * @param int $ecsid the ECS this message relates to
     * @param int $type what the notification relates to (MESSAGE_IMPORT_COURSELINK, MESSAGE_EXPORT_COURSELINK,
     *                  MESSAGE_COURSE, MESSAGE_DIRTREE, MESSAGE_USER)
     * @param int $subtype
     * @param int $dataid for courselinks: the ID of the Moodle course, for users: the ID of the new user
     * @param string $extra optional an extra message to display next to the item
     * @throws coding_exception
     */
    public static function queue_message($ecsid, $type, $subtype, $dataid, $extra = null) {
        global $DB;

        if (!in_array($type, self::$messagetypes)) {
            throw new coding_exception("Unknown message type '$type'");
        }
        if (!in_array($subtype, self::$messagesubtypes)) {
            throw new coding_exception("Unknown message subtype '$subtype'");
        }
        $ins = (object)array(
            'ecsid' => $ecsid,
            'type' => $type,
            'subtype' => $subtype,
            'data' => $dataid,
            'extra' => $extra
        );
        $DB->insert_record('local_campusconnect_notify', $ins);
    }

    /**
     * Send out all notification emails for the given ECS
     * @param ecssettings $ecssettings
     */
    public static function send_notifications(ecssettings $ecssettings) {
        global $DB;

        $types = array(
            self::MESSAGE_IMPORT_COURSELINK => (object)array(
                'string' => 'import',
                'table' => 'course',
                'name' => 'fullname',
                'url' => '/course/view.php',
                'users' => $ecssettings->get_notify_content(),
            ),
            self::MESSAGE_EXPORT_COURSELINK => (object)array(
                'string' => 'export',
                'table' => 'course',
                'name' => 'fullname',
                'url' => '/course/view.php',
                'users' => $ecssettings->get_notify_courses(),
            ),
            self::MESSAGE_USER => (object)array(
                'string' => 'newuser',
                'table' => 'user',
                'name' => 'firstname,lastname',
                'url' => '/user/view.php',
                'users' => $ecssettings->get_notify_users(),
            ),
            self::MESSAGE_COURSE => (object)array(
                'string' => 'course',
                'table' => 'course',
                'name' => 'fullname',
                'url' => '/course/view.php',
                'users' => $ecssettings->get_notify_content(),
            ),
            self::MESSAGE_DIRTREE => (object)array(
                'string' => 'directorytree',
                'table' => 'local_campusconnect_dirroot',
                'id' => 'rootid',
                'name' => 'title',
                'url' => '/local/campusconnect/admin/directorymapping.php',
                'users' => $ecssettings->get_notify_content(),
            ),
        );

        $subtypesprefix = array(
            self::TYPE_CREATE => '',
            self::TYPE_UPDATE => '_update',
            self::TYPE_DELETE => '_delete',
            self::TYPE_ERROR => '_error'
        );

        $sitename = format_string($DB->get_field('course', 'fullname', array('id' => SITEID), MUST_EXIST));
        $unknown = get_string('unknown', 'local_campusconnect');

        foreach ($types as $typeid => $type) {
            $params = array('ecsid' => $ecssettings->get_id(), 'type' => $typeid);
            $notifications = $DB->get_records('local_campusconnect_notify', $params, 'subtype');
            if ($notifications) {
                $subtypes = array();
                foreach ($notifications as $notification) {
                    if (!isset($subtypes[$notification->subtype])) {
                        $subtypes[$notification->subtype] = array();
                    }
                    $subtypes[$notification->subtype][] = $notification;
                }

                foreach ($subtypes as $subtype => $subnotifications) {
                    $prefix = $subtypesprefix[$subtype];
                    $subject = get_string("notify{$type->string}{$prefix}_subject", 'local_campusconnect', $sitename);
                    $bodytext = get_string("notify{$type->string}{$prefix}_body", 'local_campusconnect', $sitename)."\n\n";
                    $body = str_replace("\n", '<br />', $bodytext);
                    $body .= html_writer::start_tag('ul');

                    foreach ($subnotifications as $notification) {
                        $object = false;
                        if ($notification->data) {
                            $id = 'id';
                            if (isset($type->id)) {
                                $id = $type->id;
                            }
                            $object = $DB->get_record($type->table, array($id => $notification->data), "id, {$type->name}");
                        }
                        if (!$object) {
                            $msg = '';
                            if ($notification->data || !$notification->extra) {
                                $msg .= $unknown;
                            }
                            if ($notification->extra) {
                                $msg .= ($msg) ? ' - ' : '';
                                $msg .= $notification->extra;
                            }
                            $bodytext .= $msg."\n";
                            $body .= html_writer::tag('li', $msg)."\n";
                        } else {
                            $link = new moodle_url($type->url, array('id' => $object->id));
                            if ($type->name == 'firstname,lastname') {
                                $name = fullname($object);
                            } else {
                                $name = format_string($object->{$type->name});
                            }
                            $extra = ($notification->extra) ? ' - '.$notification->extra : '';
                            $bodytext .= $name.' - '.$link->out(false).$extra."\n";
                            $body .= html_writer::tag('li', html_writer::link($link, $name).$extra)."\n";
                        }
                    }
                    $body .= html_writer::end_tag('ul');

                    self::send_notification($type->users, $subject, $body, $bodytext);
                }

                $DB->delete_records('local_campusconnect_notify', $params);
            }
        }
    }

    /**
     * Send out notification to the list of users.
     * @param string[] $users usernames of the users to email
     * @param string $subject email subject
     * @param string $body HTML message content
     * @param string $bodytext plain text message content
     */
    protected static function send_notification($users, $subject, $body, $bodytext) {
        global $DB;

        if (empty($users)) {
            return;
        }
        $admin = get_admin();
        $userobjs = $DB->get_records_list('user', 'username', $users, '', 'id, email, mailformat, '.get_all_user_name_fields(true));
        foreach ($userobjs as $user) {
            email_to_user($user, $admin, $subject, $bodytext, $body);
        }
    }
}