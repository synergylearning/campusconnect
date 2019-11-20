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
 * Privacy provider - user information stored
 *
 * @package   auth_campusconnect
 * @copyright 2019 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_campusconnect\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\provider,
                          \core_privacy\local\request\plugin\provider {
    use \core_privacy\local\legacy_polyfill;

    /**
     * @param collection $collection
     * @return collection
     */
    public static function _get_metadata(collection $collection) {
        $collection->add_database_table(
            'auth_campusconnect',
            [
                'pids' => 'privacy:metadata:auth_campusconnect:pids',
                'personid' => 'privacy:metadata:auth_campusconnect:personid',
                'username' => 'privacy:metadata:auth_campusconnect:username',
                'lastenroled' => 'privacy:metadata:auth_campusconnect:lastenroled',
                'personidtype' => 'privacy:metadata:auth_campusconnect:personidtype',
                'suspended' => 'privacy:metadata:auth_campusconnect:suspended',
            ],
            'privacy:metadata:auth_campusconnect'
        );
        return $collection;
    }

    /**
     * @param int $userid
     * @return \core_privacy\local\request\contextlist
     */
    public static function _get_contexts_for_userid($userid) {
        global $DB;
        $contextlist = new contextlist();
        $sql = "
           SELECT 1
             FROM {user} u
             JOIN {auth_campusconnect} cc ON cc.username = u.username
            WHERE u.id = ?
        ";
        if ($DB->record_exists_sql($sql, [$userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function _export_user_data(approved_contextlist $contextlist) {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue; // Only interested in the system context.
            }
            if (!$rec = $DB->get_record('auth_campusconnect', ['username' => $user->username])) {
                break; // User has no record - stop now.
            }

            $path = [get_string('privacy:path:auth_campusconnect', 'auth_campusconnect')];
            $contextdata = helper::get_context_data($context, $user);
            $outrec = [
                'pids' => $rec->pids,
                'personid' => $rec->personid,
                'username' => $rec->username,
                'lastenroled' => transform::datetime($rec->lastenroled),
                'personidtype' => $rec->personidtype,
                'suspended' => transform::yesno($rec->suspended),
            ];
            $contextdata = (object)array_merge((array)$contextdata, ['record' => $outrec]);
            writer::with_context($context)->export_data($path, $contextdata);
            break; // Stop once we've processed the system context.
        }
    }

    /**
     * This will break CampusConnect logins for all users on the site.
     * @param \context $context
     */
    public static function _delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context) {
            return;
        }
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $DB->delete_records('auth_campusconnect', []);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function _delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_SYSTEM) {
                continue; // Only interested in the system context.
            }
            $DB->delete_records('auth_campusconnect', ['username' => $user->username]);
            break; // Stop once we've processed the system context.
        }
    }
}
