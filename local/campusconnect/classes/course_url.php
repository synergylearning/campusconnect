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
 * Handle 'course' creation requests from the ECS server
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Looks after passing the course URL back to the ECS when a course is created
 */
class course_url {

    /** The course url has been updated on the ECS */
    const STATUS_UPTODATE = 0;
    /** New course url, not yet sent to the ECS */
    const STATUS_CREATED = 1;
    /** Course url has changed, not yet updated the ECS */
    const STATUS_UPDATED = 2;
    /** Course url has been deleted, not yet updated the ECS */
    const STATUS_DELETED = 3;

    /** @var stdClass the crs record from the database */
    protected $crs;

    /**
     * @param int $crsid
     */
    public function __construct($crsid) {
        $this->crs = $this->get_record($crsid);
    }

    /**
     * Notify the ECS that the requested course has been created
     */
    public function add() {
        if ($this->crs->urlstatus != self::STATUS_UPTODATE) {
            throw new course_exception('\local_campusconnect\course_url::add - unexpected status for newly created crs record'.
                                       " ($this->crs->id)");
        }
        if ($this->crs->urlresourceid != 0) {
            throw new course_exception('\local_campusconnect\course_url::add - newly created crs record should not have a'.
                                       " urlresourceid ($this->crs->id)");
        }
        $this->set_status(self::STATUS_CREATED);
    }

    /**
     * Notify the ECS that the URL of the course has changed
     */
    public function update() {
        if ($this->crs->urlstatus == self::STATUS_CREATED || $this->crs->urlstatus == self::STATUS_UPDATED) {
            return; // Nothing to do - updates already pending.
        }
        if ($this->crs->urlstatus == self::STATUS_DELETED) {
            throw new course_exception('\local_campusconnect\course_url::update - attempting to update crs record'.
                                       " ($this->crs->id) that is scheduled for deletion");
        }
        if ($this->crs->urlresourceid) {
            $this->set_status(self::STATUS_UPDATED);
        } else {
            // Catching odd situations in which no URL has been created yet, so switching to CREATE instead of UPDATE.
            $this->set_status(self::STATUS_CREATED);
        }
    }

    /**
     * Notify the ECS that this course has been deleted
     */
    public function delete() {
        global $DB;

        if ($this->crs->urlstatus == self::STATUS_CREATED) {
            // Never reached the ECS server - just delete it.
            $DB->delete_records('local_campusconnect_crs', array('id' => $this->crs->id));
            return;
        }
        if ($this->crs->urlresourceid == 0) {
            throw new course_exception('\local_campusconnect\course_url::delete - cannot delete record on ECS with no'.
                                       " urlresourceid ($this->crs->id)");
        }

        $this->set_status(self::STATUS_DELETED);
    }

    /**
     * Update the ECS with all the changes to course urls
     * @param connect $connect
     */
    public static function update_ecs(connect $connect) {
        global $DB;

        /** @var $cms participantsettings */
        $cms = participantsettings::get_cms_participant();
        if (!$cms) {
            return;
        }

        if ($connect->get_ecs_id() != $cms->get_ecs_id()) {
            return; // Not updating the ECS that the CMS is on.
        }

        // Find all crs records which need the URL updating, then load all crs records with matching resourceids
        // (as all alternative URLs need including in the course URL response).
        $courseurls = self::get_urls_to_export($connect->get_ecs_id(), false);

        // Update/create all the courseurl resources on the ECS server.
        foreach ($courseurls as $courseurl) {
            if ($courseurl->urlstatus == self::STATUS_DELETED) {
                // Delete from ECS then delete the local record.
                try {
                    $connect->delete_resource($courseurl->urlresourceid, event::RES_COURSE_URL);
                    $DB->delete_records('local_campusconnect_crs', array('id' => $courseurl->id));
                } catch (connect_exception $e) {
                    // Ignore exceptions - resource may no longer exist.
                }
                continue;
            }

            // Prepare the course_url data object.
            $data = self::prepare_courseurl_data($courseurl, $connect);

            if ($courseurl->urlstatus == self::STATUS_UPDATED) {
                if (!$courseurl->resourceid) {
                    $courseurl->urlstatus = self::STATUS_CREATED;
                    debugging("\\local_campusconnect\\course_url::update_ecs - cannot update course url ({$courseurl->id})".
                              " without resourceid (creating new resource instead)");
                }
            }

            // Update ECS server.
            if ($courseurl->urlstatus == self::STATUS_CREATED) {
                $urlresourceid = $connect->add_resource(event::RES_COURSE_URL, $data, null, $cms->get_mid());
            }
            if ($courseurl->urlstatus == self::STATUS_UPDATED) {
                $connect->update_resource($courseurl->urlresourceid, event::RES_COURSE_URL, $data, null, $cms->get_mid());
            }

            // Update all local crs records associated with this courseurl.
            $upd = new stdClass();
            $upd->urlstatus = self::STATUS_UPTODATE;
            $upd->urlresourceid = $courseurl->urlresourceid;
            if (!empty($urlresourceid)) {
                $upd->urlresourceid = $urlresourceid;
            }
            foreach ($courseurl->ids as $id) {
                $upd->id = $id;
                $DB->update_record('local_campusconnect_crs', $upd);
            }
        }
    }

    /**
     * Get list of course URLS from ECS - delete any that should not be there any more, create
     * any that should be there and update all others
     * NOTE: This does not work as the list of courseurls pulled from the ECS does not include our links.
     * @param connect $connect
     * @return object an object containing: ->created = array of resourceids created
     *                            ->updated = array of resourceids updated
     *                            ->deleted = array of resourceids deleted
     */
    public static function refresh_ecs(connect $connect) {
        global $DB;

        $ret = (object)array('created' => array(), 'updated' => array(), 'deleted' => array());

        $cms = participantsettings::get_cms_participant();
        if (!$cms || $connect->get_ecs_id() != $cms->get_ecs_id()) {
            return $ret; // Not updating the ECS that the CMS is on.
        }

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

        // Get a list of the course URLS, as they all should all be exported.
        $exportedcourseurls = self::get_urls_to_export($connect->get_ecs_id(), false);

        // Index the exported URLs by resourceid, so they can be looked up below.
        $mapping = array();
        foreach ($exportedcourseurls as $courseurl) {
            if ($courseurl->urlresourceid) {
                $mapping[$courseurl->urlresourceid] = $courseurl->id;
            }
        }

        // Check all the resources on the server against our local list.
        $resources = $connect->get_resource_list(event::RES_COURSE_URL, connect::SENT);
        foreach ($resources->get_ids() as $resourceid) {
            $transferdetails = $connect->get_resource($resourceid, event::RES_COURSE_URL, true);
            if (!$transferdetails->sent_by_me($mymids)) {
                continue; // Not one of this VLE's resources.
            }

            if (!isset($mapping[$resourceid])) {
                // This VLE does not have that course url - need remove from ECS.
                // (Not that this should ever happen).
                $connect->delete_resource($resourceid, event::RES_COURSE_URL);
                $ret->deleted[] = $resourceid;
            } else {
                // Course url is present in VLE and on ECS - update with latest details.
                $courseurl = $exportedcourseurls[$mapping[$resourceid]];

                // Prepare the course_url data object.
                $data = self::prepare_courseurl_data($courseurl, $connect);

                $connect->update_resource($resourceid, event::RES_COURSE_URL, $data, null, $cms->get_mid());

                $courseurl->updated = true;
                $ret->updated[] = $resourceid;
            }
        }

        // Check for any course urls that were not found on the ECS.
        foreach ($exportedcourseurls as $courseurl) {
            if (!empty($courseurl->updated)) {
                continue; // Already updated.
            }

            // Course not found on ECS - add it (should not happen).

            // Prepare the course_url data object.
            $data = self::prepare_courseurl_data($courseurl, $connect);
            $resourceid = $connect->add_resource(event::RES_COURSE_URL, $data, null, $cms->get_mid());

            // Update all local crs records associated with this url resource.
            $upd = new stdClass();
            $upd->urlresourceid = $resourceid;
            foreach ($courseurl->ids as $id) {
                $upd->id = $id;
                $DB->update_record('local_campusconnect_crs', $upd);
            }
            $ret->created[] = $resourceid;
        }

        return $ret;
    }

    /**
     * Gets the details of the course urls that need exporting
     *
     * @param int $ecsid
     * @param bool $onlyupdated (optional) set to false to retrieve all course URLs
     * @return object[]
     */
    protected static function get_urls_to_export($ecsid, $onlyupdated = true) {
        global $DB;

        $select = 'ecsid = :ecsid';
        $params = array('ecsid' => $ecsid);
        if ($onlyupdated) {
            $select .= ' AND urlstatus <> :uptodate';
            $params['uptodate'] = self::STATUS_UPTODATE;
        }

        $allcourseids = array();
        $updatedresourceids = $DB->get_fieldset_select('local_campusconnect_crs', 'DISTINCT(resourceid)', $select, $params);
        if (!$updatedresourceids) {
            return array(); // Nothing to export.
        }

        // Note, internal links are not exported, as they are not separate courses, from the point of view of the external CMS.
        list($rsql, $params) = $DB->get_in_or_equal($updatedresourceids, SQL_PARAMS_NAMED);
        $courseurls = $DB->get_records_select('local_campusconnect_crs', "resourceid $rsql AND internallink = 0",
                                              $params, 'resourceid, internallink, id');

        // Loop throught he courseurls and combine together those that match a single resourceid.
        /** @var stdClass $lasturl */
        $lasturl = null;
        foreach ($courseurls as $key => $courseurl) {
            $allcourseids[] = $courseurl->courseid;
            if ($lasturl && $lasturl->resourceid == $courseurl->resourceid) {
                // Sorted by 'resourceid', so they can be easily grouped by that value.
                $lasturl->courses[] = (object)array('id' => $courseurl->courseid);
                $lasturl->ids[] = $courseurl->id;
                if (!$lasturl->urlresourceid) {
                    // Make sure we keep the existing urlresourceid record.
                    $lasturl->urlresourceid = $courseurl->urlresourceid;
                } else {
                    if ($courseurl->urlresourceid && $lasturl->urlresourceid != $courseurl->urlresourceid) {
                        debugging('Mulitple urlresourceids found for a set of parallel groups - these will be combined');
                    }
                }
                unset($courseurls[$key]);
            } else {
                $courseurl->courses = array((object)array('id' => $courseurl->courseid));
                $courseurl->ids = array($courseurl->id);
                $lasturl = $courseurl;
            }
        }
        $courses = array();
        if ($allcourseids) {
            list($csql, $params) = $DB->get_in_or_equal($allcourseids);
            $courses = $DB->get_records_select_menu('course', "id {$csql}", $params, '', 'id, fullname');
        }

        // Match up the course ids with their names.
        foreach ($courseurls as $courseurl) {
            foreach ($courseurl->courses as $course) {
                if (isset($courses[$course->id])) {
                    $course->fullname = $courses[$course->id];
                } else {
                    $course->fullname = '-';
                }
            }
        }

        return $courseurls;
    }

    /**
     * Convert the courseurl data into an object that can be sent to the ECS
     *
     * @param object $courseurl
     * @param connect $connect
     * @return stdClass
     */
    protected static function prepare_courseurl_data($courseurl, connect $connect) {
        $moodleurls = array();
        foreach ($courseurl->courses as $course) {
            $moodleurl = new moodle_url('/course/view.php', array('id' => $course->id));
            $moodleurls[] = (object)array(
                'title' => $course->fullname,
                'url' => $moodleurl->out(),
            );
        }
        $data = new stdClass();
        $data->cms_course_id = $courseurl->cmsid.''; // Convert to string if 'NULL'.
        $data->ecs_course_url = $connect->get_resource_url($courseurl->resourceid, event::RES_COURSE);
        $data->lms_course_urls = $moodleurls;

        return $data;
    }

    /**
     * Load the crs record and check it is valid
     * @param $crsid
     * @return mixed
     * @throws course_exception
     */
    protected function get_record($crsid) {
        global $DB;

        $crs = $DB->get_record('local_campusconnect_crs', array('id' => $crsid), '*', MUST_EXIST);
        if ($crs->internallink != 0) {
            throw new course_exception("Should not be sending course_url resources for internal course links (crsid = $crsid)");
        }

        return $crs;
    }

    /**
     * Update the status of the course link
     * @param $status
     */
    protected function set_status($status) {
        global $DB;

        $upd = new stdClass();
        $upd->id = $this->crs->id;
        $upd->urlstatus = $status;
        $this->crs->urlstatus = $status;
        if ($status == self::STATUS_DELETED) {
            $upd->resourceid = 0; // Remove the rest of the details, as they are no longer needed.
            $upd->internallink = 0;
            $this->crs->resouceid = 0;
            $this->crs->internallink = 0;
        }
        $DB->update_record('local_campusconnect_crs', $upd);
    }
}
