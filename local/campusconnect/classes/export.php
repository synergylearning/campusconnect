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
 * Export courses to ECS server
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use Exception;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class export {

    // Holds the status of the exported course until the ECS has been updated.
    const STATUS_UPTODATE = 0;
    const STATUS_CREATED = 1;
    const STATUS_UPDATED = 2;
    const STATUS_DELETED = 3;

    protected $exportparticipants = null;
    protected $exportsettings = null;
    protected $courseid = null;

    public function __construct($courseid) {
        global $DB, $SITE;

        if ($courseid == $SITE->id) {
            throw new coding_exception("The SITE course is not eligable for export via CampusConnect");
        }

        $this->courseid = $courseid;
        $this->exportsettings = $DB->get_records('local_campusconnect_export', array('courseid' => $this->courseid));
        $mids = array();
        foreach ($this->exportsettings as $setting) {
            if ($setting->status != self::STATUS_DELETED) {
                $mids[$setting->ecsid] = explode(',', $setting->mids);
            }
        }

        $this->exportparticipants = participantsettings::list_potential_export_participants();

        foreach ($this->exportparticipants as $part) {
            $exported = array_key_exists($part->get_ecs_id(), $mids);
            $exported = $exported && in_array($part->get_mid(), $mids[$part->get_ecs_id()]);
            $part->show_exported($exported);
        }
    }

    /**
     * Returns the courseid that this export object is for
     * @return int $courseid
     */
    public function get_courseid() {
        return $this->courseid;
    }

    /**
     * Returns the update status of a particular participant
     * @param string $partidentifier
     */
    public function get_status($partidentifier) {
        if (!array_key_exists($partidentifier, $this->exportparticipants)) {
            throw new coding_exception("Attempting to get the status of a participant ($partidentifier)".
                                       " not in the available to export to list");
        }
        $ecsid = $this->exportparticipants[$partidentifier]->get_ecs_id();
        foreach ($this->exportsettings as $setting) {
            if ($setting->ecsid == $ecsid) {
                return $setting->status;
            }
        }

        throw new coding_exception("Attempting to get the status of a participant ($partidentifier)".
                                   " not currently being exported to");
    }

    /**
     * Is this course exported to any participant in any ECS?
     * @return bool true if exported to at least one participant
     */
    public function is_exported() {
        // See if we have a non-deleted export record for any ECS.
        foreach ($this->exportsettings as $setting) {
            if ($setting->status != self::STATUS_DELETED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the course is exported to the given participant.
     *
     * @param int $ecsid
     * @param int|int[] $mid
     * @return bool
     */
    public function is_exported_to($ecsid, $mid) {
        if (!is_array($mid)) {
            $mid = array($mid);
        }
        foreach ($this->exportparticipants as $part) {
            if ($part->get_ecs_id() == $ecsid && in_array($part->get_mid(), $mid)) {
                if ($part->is_exported()) {
                    return true;
                }
            }
        }
        return false;
    }

    public function get_participant($ecsid, $mid) {
        foreach ($this->exportparticipants as $part) {
            if ($part->get_ecs_id() == $ecsid && $part->get_mid() == $mid) {
                return $part;
            }
        }
        return null;
    }

    public function should_handle_auth_token($ecsid, $mid) {
        $part = $this->get_participant($ecsid, $mid);
        return ($part && $part->is_exported() && $part->is_export_token_enabled());
    }

    public function should_send_enrolment_status($ecsid, $mid) {
        $part = $this->get_participant($ecsid, $mid);
        return ($part && $part->is_exported() && $part->is_export_enrolment_enabled());
    }

    /**
     * List all the participants this course is currently exported to.
     * @return participantsettings[] ecsid_mid => \local_campusconnect\participantsettings
     */
    public function list_current_exports() {
        $ret = array();
        foreach ($this->exportparticipants as $identifier => $part) {
            if ($part->is_exported()) {
                $ret[$identifier] = $part;
            }
        }
        return $ret;
    }

    /**
     * Returns a list of potential participants to export to. Use $part->display_name()
     * for the name to display and $part->is_exported() to see if it is currently exported
     * @return participantsettings[]  ecsid_mid => \local_campusconnect\participantsettings
     */
    public function list_participants() {
        return $this->exportparticipants;
    }

    /**
     * Set the export status for an individual participant
     * @param mixed $identifier string | participantsettings  - ecsid_mid for the participant
     * @param bool $export true to export to them, false to not export
     * @return void
     */
    public function set_export($identifier, $export) {
        global $DB;

        if (is_object($identifier) && get_class($identifier) == '\local_campusconnect\participantsettings') {
            $ecsid = $identifier->get_ecs_id();
            $mid = $identifier->get_mid();
        } else {
            if (!array_key_exists($identifier, $this->exportparticipants)) {
                throw new coding_exception("Attempting to set the exported value of a participant ($identifier)".
                                           " not in the available to export to list");
            }
            $ecsid = $this->exportparticipants[$identifier]->get_ecs_id();
            $mid = $this->exportparticipants[$identifier]->get_mid();
            $this->exportparticipants[$identifier]->show_exported($export);
        }

        foreach ($this->exportsettings as $setting) {
            if ($setting->ecsid == $ecsid) {
                // We already have a local export record for this course & ECS.
                $mids = array_filter(explode(',', $setting->mids));
                if ($export) {
                    if (in_array($mid, $mids)) {
                        return; // Already on list to export to.
                    }
                    $mids[] = $mid;
                } else {
                    if (($key = array_search($mid, $mids)) === false) {
                        return; // Wasn't in the export list anyway.
                    }
                    unset($mids[$key]);
                }
                $upd = new stdClass();
                $upd->id = $setting->id;
                $upd->mids = implode(',', $mids);
                if ($setting->status != self::STATUS_CREATED) {
                    // ECS server already knows about this resource => send update or delete as appropriate.
                    if (empty($mids)) {
                        $upd->status = self::STATUS_DELETED;
                    } else {
                        $upd->status = self::STATUS_UPDATED;
                    }
                } else {
                    if (empty($mids)) {
                        // ECS server never received the 'create' message => just delete the local export record.
                        $DB->delete_records('local_campusconnect_export', array('id' => $upd->id));
                        unset($this->exportsettings[$upd->id]);
                        return;
                    }
                }
                // Update the database and the exportsettings array.
                $DB->update_record('local_campusconnect_export', $upd);
                $this->exportsettings[$upd->id]->mids = $upd->mids;
                if (isset($upd->status)) {
                    $this->exportsettings[$upd->id]->status = $upd->status;
                }
                return;
            }
        }

        // No current export record for this course & ECS => create one (if needed).
        if ($export) {
            $ins = new stdClass();
            $ins->courseid = $this->courseid;
            $ins->ecsid = $ecsid;
            $ins->mids = $mid;
            $ins->status = self::STATUS_CREATED;
            $ins->id = $DB->insert_record('local_campusconnect_export', $ins);
            $this->exportsettings[$ins->id] = $ins;
        }
    }

    /**
     * Update ECS with course update if this course is currently exported
     */
    public function updated() {
        global $DB;

        foreach ($this->exportsettings as $setting) {
            if ($setting->status == self::STATUS_UPTODATE) {
                // Need to inform ECS about update.
                $upd = new stdClass();
                $upd->id = $setting->id;
                $upd->status = self::STATUS_UPDATED;
                $DB->update_record('local_campusconnect_export', $upd);
                $this->exportsettings[$setting->id]->status = self::STATUS_UPDATED;
            }
            // Else - Update already pending, no changes needed.
        }
    }

    /**
     * Update ECS with course deletion if this course was exported
     */
    public function deleted() {
        global $DB;

        foreach ($this->exportsettings as $setting) {
            if ($setting->status == self::STATUS_CREATED) {
                // ECS never knew about this course - just delete the record.
                $DB->delete_records('local_campusconnect_export', array('id' => $setting->id));
                unset($this->exportsettings[$setting->id]);
            } else {
                // Need to inform ECS server about this deletion.
                $upd = new stdClass();
                $upd->id = $setting->id;
                $upd->status = self::STATUS_DELETED;
                $DB->update_record('local_campusconnect_export', $upd);
                $this->exportsettings[$setting->id]->status = self::STATUS_DELETED;
            }
        }
    }

    /**
     * Set this course to not be exported to any participants
     */
    public function clear_exports() {
        global $DB;

        foreach ($this->exportsettings as $setting) {
            if ($setting->status == self::STATUS_CREATED) {
                $DB->delete_records('local_campusconnect_export', array('id' => $setting->id));
                unset($this->exportsettings[$setting->id]);

            } else if ($setting->status != self::STATUS_DELETED) {
                $upd = new stdClass();
                $upd->id = $setting->id;
                $upd->mids = '';
                $upd->status = self::STATUS_DELETED;
                $DB->update_record('local_campusconnect_export', $upd);

                $this->exportsettings[$upd->id]->mids = '';
                $this->exportsettings[$upd->id]->status = self::STATUS_DELETED;
            }
        }
    }

    /**
     * Send out course update messages to all ECS we are registered with.
     */
    public static function update_all_ecs() {
        $ecslist = ecssettings::list_ecs();
        foreach ($ecslist as $ecsid => $ecsname) {
            $settings = new ecssettings($ecsid);
            $connect = new connect($settings);
            self::update_ecs($connect);
        }
    }

    /**
     * Send out course update messages to a single ECS.
     * @param connect $connect - a connection to the specific ECS
     * @param array $unittestdata - course data to use when unit testing
     */
    public static function update_ecs(connect $connect, $unittestdata = null) {
        global $DB;

        $activemids = array();
        foreach (participantsettings::list_potential_export_participants() as $part) {
            if ($part->get_ecs_id() == $connect->get_ecs_id()) {
                $activemids[] = $part->get_mid();
            }
        }

        // Get a list of all the courses that need updating on the ECS server.
        $updated = $DB->get_records_select('local_campusconnect_export', 'ecsid = :ecsid AND status <> :uptodate',
                                           array('ecsid' => $connect->get_ecs_id(), 'uptodate' => self::STATUS_UPTODATE));
        foreach ($updated as $export) {
            if ($export->status == self::STATUS_DELETED) {
                // Delete from ECS server, then delete local record.
                $connect->delete_resource($export->resourceid, event::RES_COURSELINK);
                $DB->delete_records('local_campusconnect_export', array('id' => $export->id));
                notification::queue_message($connect->get_ecs_id(),
                                            notification::MESSAGE_EXPORT_COURSELINK,
                                            notification::TYPE_DELETE,
                                            $export->courseid);
                mtrace("No longer exporting course id {$export->courseid} as resource {$export->resourceid}");
                continue;
            }

            // Make sure we only try to export to participants that existed, last time we checked.
            $mids = explode(',', $export->mids);
            $mids = array_intersect($mids, $activemids);
            if (!$mids) {
                continue; // Not exporting to any valid mids.
            }
            $mids = implode(',', $mids);

            // Get the course data & adjust using meta-data mapping rules.
            if (is_null($unittestdata)) {
                $course = $DB->get_record('course', array('id' => $export->courseid), '*', MUST_EXIST);
            } else {
                $course = $unittestdata[$export->courseid];
            }
            $metadata = new metadata($connect->get_settings());
            $data = $metadata->map_course_to_remote($course);
            $data->url = self::get_course_url($course);

            // Update ECS server.
            if ($export->status == self::STATUS_UPDATED) {
                if (!$connect->get_resource($export->resourceid, event::RES_COURSELINK)) {
                    $export->status = self::STATUS_CREATED; // Resource not found => create a new resource.
                }
            }
            if ($export->status == self::STATUS_CREATED) {
                $resourceid = $connect->add_resource(event::RES_COURSELINK, $data, null, $mids);

                notification::queue_message($connect->get_ecs_id(),
                                            notification::MESSAGE_EXPORT_COURSELINK,
                                            notification::TYPE_CREATE,
                                            $course->id);
                mtrace("Exported course id $course->id to mids {$export->mids} as resource $resourceid");
            }
            if ($export->status == self::STATUS_UPDATED) {
                $connect->update_resource($export->resourceid, event::RES_COURSELINK, $data, null, $mids);

                notification::queue_message($connect->get_ecs_id(),
                                            notification::MESSAGE_EXPORT_COURSELINK,
                                            notification::TYPE_UPDATE,
                                            $course->id);
                mtrace("Updated exported course id $course->id to mids {$export->mids} as resource {$export->resourceid}");
            }

            // Update local export record.
            $upd = new stdClass();
            $upd->id = $export->id;
            $upd->status = self::STATUS_UPTODATE;
            if (isset($resourceid)) {
                $upd->resourceid = $resourceid;
            }
            $DB->update_record('local_campusconnect_export', $upd);
        }
    }

    /**
     * Remove, from the ECS server, all the exports linked to a particular MID
     * (Warning - this removes the 'export' settings as well, so the courses will not be automatically
     * exported again if re-enabled).
     * @param participantsettings $participant
     * @return void
     */
    public static function delete_mid_exports(participantsettings $participant) {
        global $DB;

        // Find all the exports to this participant.
        $exportrecords = $DB->get_records('local_campusconnect_export', array('ecsid' => $participant->get_ecs_id()));
        foreach ($exportrecords as $id => $exportrecord) {
            $mids = explode(',', $exportrecord->mids);
            if (!in_array($participant->get_mid(), $mids)) {
                unset($exportrecords[$id]);
            }
        }
        if (empty($exportrecords)) {
            return; // Nothing currently exported to this participant.
        }

        // Stop exporting to this participant.
        foreach ($exportrecords as $exportrecord) {
            $export = new export($exportrecord->courseid);
            $export->set_export($participant, false);
        }
    }

    /**
     * Resync the exported courses with the ECS
     */
    public static function refresh_all_ecs() {
        $errors = array();
        $ecslist = ecssettings::list_ecs();
        foreach ($ecslist as $ecsid => $ecs) {
            $settings = new ecssettings($ecsid);
            $connect = new connect($settings);
            try {
                self::refresh_ecs($connect);
            } catch (Exception $e) {
                $errors[] = $ecs.': '.$e->getMessage();
            }
        }
        return $errors;
    }

    /**
     * Get list of exported courses from ECS - delete any that should not be there any more, create
     * any that should be there and update all others
     * @param connect $connect connection to the ECS to update
     * @param bool $preview optional true to report the changes needed, but don't make them
     * @return object an object containing: ->created = array of resourceids created
     *                            ->updated = array of resourceids updated
     *                            ->deleted = array of resourceids deleted
     */
    public static function refresh_ecs(connect $connect, $preview = false) {
        global $DB;

        $ret = (object)array('created' => array(), 'updated' => array(), 'deleted' => array());

        // Start by updating ECS with any recent changes.
        self::update_ecs($connect);

        // Get a list of MIDs that this site is known by.
        $mymids = array();
        $knownmids = array();
        $memberships = $connect->get_memberships();
        foreach ($memberships as $membership) {
            foreach ($membership->participants as $participant) {
                if ($participant->itsyou) {
                    $mymids[] = $participant->mid;
                } else {
                    $knownmids[] = $participant->mid;
                }
            }
        }

        // Get a list of the courses we have exported.
        $exportedcourses = $DB->get_records('local_campusconnect_export', array('ecsid' => $connect->get_ecs_id()), '',
                                            'resourceid, id, courseid, mids');
        $exportedresourceids = array_keys($exportedcourses);
        $metadata = new metadata($connect->get_settings());

        // Check all the resources on the server against our local list.
        $resources = $connect->get_resource_list(event::RES_COURSELINK, connect::SENT);
        foreach ($resources->get_ids() as $resourceid) {
            $transferdetails = $connect->get_resource($resourceid, event::RES_COURSELINK,
                                                      connect::TRANSFERDETAILS);
            if (!$transferdetails->sent_by_me($mymids)) {
                continue; // Not one of this VLE's resources.
            }

            if (!in_array($resourceid, $exportedresourceids)) {
                // This VLE does not have that course - need remove from ECS.
                // (Not that this should ever happen).
                if (!$preview) {
                    $connect->delete_resource($resourceid, event::RES_COURSELINK);
                }
                $ret->deleted[] = $resourceid;
            } else {
                // Course is present in VLE and on ECS - update with latest details.
                $courseid = $exportedcourses[$resourceid]->courseid;
                $mids = $exportedcourses[$resourceid]->mids;

                // Check all the destination mids are still in the communities.
                $mids = explode(',', $mids);
                $mids = array_intersect($mids, $knownmids);
                $mids = implode(',', $mids);
                if (!$mids) {
                    // None of the recipients are in the same community any more
                    // => delete the resource from ECS and local export list.
                    if (!$preview) {
                        $connect->delete_resource($resourceid, event::RES_COURSELINK);
                        $DB->delete_records('local_campusconnect_export', array('id' => $exportedcourses[$resourceid]->id));
                    }
                    unset($exportedcourses[$resourceid]);
                    $ret->deleted[] = $resourceid;
                } else {
                    $course = $DB->get_record('course', array('id' => $courseid));
                    $data = $metadata->map_course_to_remote($course);
                    $data->url = self::get_course_url($course);

                    if (!$preview) {
                        $connect->update_resource($resourceid, event::RES_COURSELINK, $data, null, $mids);
                    }

                    $exportedcourses[$resourceid]->updated = true;
                    $ret->updated[] = $resourceid;
                }
            }
        }

        // Check for any courses that were not found on the ECS.
        foreach ($exportedcourses as $exportedcourse) {
            if (!empty($exportedcourse->updated)) {
                continue; // Already updated.
            }

            // Course not found on ECS - add it (should not happen).
            $courseid = $exportedcourse->courseid;
            $mids = $exportedcourse->mids;

            // Check all the destination mids are still in the communities.
            $mids = explode(',', $mids);
            $mids = array_intersect($mids, $knownmids);
            $mids = implode(',', $mids);
            if (!$mids) {
                continue;
            }

            $course = $DB->get_record('course', array('id' => $courseid));
            $data = $metadata->map_course_to_remote($course);
            $data->url = self::get_course_url($course);

            $resourceid = 0;
            if (!$preview) {
                $resourceid = $connect->add_resource(event::RES_COURSELINK, $data, null, $mids);

                $upd = new stdClass();
                $upd->id = $exportedcourse->id;
                $upd->resourceid = $resourceid;
                $DB->update_record('local_campusconnect_export', $upd);
            }
            $ret->created[] = $resourceid;
        }

        return $ret;
    }

    /**
     * Delete all course exports for this ECS - may fail if the ECS cannot be contacted
     * @param int $ecsid - the ECS to delete
     * @param bool $force optional - set to true to delete even if the ECS connection fails
     */
    public static function delete_ecs_exports($ecsid, $force = false) {
        global $DB;

        $exports = $DB->get_records('local_campusconnect_export', array('ecsid' => $ecsid));
        if ($exports) {
            $ecssettings = new ecssettings($ecsid);
            $connect = new connect($ecssettings);
            foreach ($exports as $export) {
                if ($export->status != self::STATUS_CREATED) {
                    try {
                        $connect->delete_resource($export->resourceid, event::RES_COURSELINK);
                        $DB->delete_records('local_campusconnect_export', array('id' => $export->id));
                    } catch (Exception $e) {
                        if (!$force) {
                            throw $e;
                        }
                    }
                }
            }
            // Final clean-up.
            $DB->delete_records('local_campusconnect_export', array('ecsid' => $ecsid));
        }
    }

    /**
     * List all courses exported by this VLE
     * @param int $ecsid optional
     * @param int $mid optional
     * @return export[]
     */
    public static function list_all_exports($ecsid = null, $mid = null) {
        global $DB;

        $select = '';
        $params = array();
        if (!is_null($ecsid)) {
            $select = 'ecsid = :ecsid';
            $params['ecsid'] = $ecsid;
        }
        if (is_null($mid)) {
            // Get all exported courses (optionally for a specific ECS).
            $courseids = $DB->get_fieldset_select('local_campusconnect_export', 'DISTINCT courseid', $select, $params);
        } else {
            if (is_null($ecsid)) {
                throw new coding_exception("campusconnect_export::list_all_exports - must specify ecsid when specifying mid");
            }
            // Get the exported courses for a particular participant.
            $courseids = array();
            $exports = $DB->get_records_select('local_campusconnect_export', $select, $params);
            foreach ($exports as $export) {
                $mids = explode(',', $export->mids);
                if (in_array($mid, $mids)) {
                    $courseids[$export->courseid] = $export->courseid;
                }
            }
        }
        $exports = array();
        foreach ($courseids as $courseid) {
            $exports[] = new export($courseid);
        }
        return $exports;
    }

    /**
     * Return the course url to use in the exported course_link + enrolment_status resources.
     * @param $course
     * @return \moodle_url
     */
    public static function get_course_url($course) {
        $url = new moodle_url('/local/campusconnect/viewcourse.php', array('id' => $course->id));
        return $url->out();
    }

    /**
     * Return the courseID for use in the enrolment_status resource.
     * @param object $course
     * @param connect $connect
     */
    public static function get_course_id($course, $connect) {
        $metadata = new metadata($connect->get_settings());
        $data = $metadata->map_course_to_remote($course);
        return $data->id;
    }

    public static function course_updated(\core\event\course_updated $event) {
        $export = new export($event->courseid);
        $export->updated();
    }

    public static function course_deleted(\core\event\course_deleted $event) {
        $export = new export($event->courseid);
        $export->deleted();
    }

}