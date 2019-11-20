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
 * @package   local_campusconnect
 * @copyright 2019 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect\privacy;

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
            'local_campusconnect_mbr',
            [
                'resourceid' => 'privacy:metadata:local_campusconnect_mbr:resourceid',
                'cmscourseid' => 'privacy:metadata:local_campusconnect_mbr:cmscourseid',
                'personid' => 'privacy:metadata:local_campusconnect_mbr:personid',
                'personidtype' => 'privacy:metadata:local_campusconnect_mbr:personidtype',
                'role' => 'privacy:metadata:local_campusconnect_mbr:role',
                'status' => 'privacy:metadata:local_campusconnect_mbr:status',
                'parallelgroups' => 'privacy:metadata:local_campusconnect_mbr:parallelgroups',
            ],
            'privacy:metadata:local_campusconnect_mbr'
        );
        return $collection;
    }

    /**
     * @param int $userid
     * @return \core_privacy\local\request\contextlist
     */
    public static function _get_contexts_for_userid($userid) {
        $contextlist = new contextlist();
        $sql = "
           SELECT ctx.id
             FROM {course} c
             JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = ".CONTEXT_COURSE."
             JOIN {local_campusconnect_crs} crs ON crs.courseid = c.id
             JOIN {local_campusconnect_mbr} mbr ON mbr.cmscourseid = crs.cmsid
             JOIN {auth_campusconnect} acc ON acc.personid = mbr.personid AND acc.personidtype = mbr.personidtype
             JOIN {user} u ON u.username = acc.username
            WHERE u.id = ?
        ";
        $params = [$userid];
        $contextlist->add_from_sql($sql, $params);

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
        if (!$authrec = $DB->get_record('auth_campusconnect', ['username' => $user->username], 'personid, personidtype')) {
            return;
        }

        $path = [get_string('privacy:path:local_campusconnect_mbr', 'local_campusconnect')];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue; // Only interested in course context.
            }

            $sql = "
                SELECT mbr.*
                  FROM {local_campusconnect_mbr} mbr
                  JOIN {local_campusconnect_crs} crs ON crs.cmsid = mbr.cmscourseid
                 WHERE mbr.personid = :personid
                   AND mbr.personidtype = :personidtype
                   AND crs.courseid = :courseid
            ";
            $params = [
                'personid' => $authrec->personid,
                'personidtype' => $authrec->personidtype,
                'courseid' => $context->instanceid,
            ];

            $mbrsout = [];
            foreach ($DB->get_records_sql($sql, $params) as $mbr) {
                $mbrsout[] = [
                    'resourceid' => $mbr->resourceid,
                    'cmscourseid' => $mbr->cmscourseid,
                    'personid' => $mbr->personid,
                    'personidtype' => $mbr->personidtype,
                    'role' => $mbr->role,
                    'status' => $mbr->status,
                    'parallelgroups' => $mbr->parallelgroups,
                ];
            }

            $contextdata = helper::get_context_data($context, $user);
            $contextdata = (object)array_merge((array)$contextdata, ['memberships' => $mbrsout]);
            writer::with_context($context)->export_data($path, $contextdata);
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
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        if ($cmscourseid = $DB->get_field('local_campusconnect_crs', 'cmsid', ['courseid' => $context->instanceid])) {
            $DB->delete_records('local_campusconnect_mbr', ['cmscourseid' => $cmscourseid]);
        }
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
        if (!$authrec = $DB->get_record('auth_campusconnect', ['username' => $user->username], 'personid, personidtype')) {
            return;
        }
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue; // Only interested in the course context.
            }
            if (!$cmscourseid = $DB->get_field('local_campusconnect_crs', 'cmsid', ['courseid' => $context->instanceid])) {
                continue;
            }
            $params = [
                'personid' => $authrec->personid,
                'personidtype' => $authrec->personidtype,
                'cmscourseid' => $cmscourseid,
            ];
            $DB->delete_records('local_campusconnect_mbr', $params);
        }
    }
}
