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

use coding_exception;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Looks after the creation / update of courses based on requests from the CMS (via the ECS)
 */
class course {

    protected static $enabled = null;

    /**
     * Is course creation enabled?
     * @return bool
     */
    public static function enabled() {
        if (is_null(self::$enabled)) {
            self::$enabled = get_config('local_campusconnect', 'courseenabled');
            if (self::$enabled === false) {
                self::$enabled = 1; // Default to enabled, if no value yet saved.
            }
        }
        return (self::$enabled != 0);
    }

    /**
     * Update the settings for course creation.
     * @param $enabled
     */
    public static function set_enabled($enabled) {
        $val = $enabled ? 1 : 0;
        if (self::$enabled != $val) {
            set_config('courseenabled', $val, 'local_campusconnect');
            self::$enabled = $val;
        }
    }

    /**
     * Used by the ECS event processing to create new courses
     * @param int $resourceid - the ID on the ECS server
     * @param ecssettings $ecssettings - the ECS being connected to
     * @param object $course - the resource data from ECS
     * @param details $transferdetails - the metadata for the resource on the ECS
     * @param participantsettings $cms - passed in when doing full refresh (to save a DB query)
     * @return bool true if successful
     */
    public static function create($resourceid, ecssettings $ecssettings, $course,
                                  details $transferdetails, participantsettings $cms = null) {
        if (is_null($cms)) {
            $cms = participantsettings::get_cms_participant();
        }
        $mid = $transferdetails->get_sender_mid();
        $ecsid = $ecssettings->get_id();
        if (!$cms || $cms->get_mid() != $mid || $cms->get_ecs_id() != $ecsid) {
            log::add("Recieved create course ({$resourceid}) event from non-CMS participant");
            return true; // Remove the event.
        }
        if (is_array($course)) {
            log::add("Course resource ({$resourceid}) should contain a single course, not an array of courses");
            $course = reset($course);
        }
        if (empty($course)) {
            throw new coding_exception('Should not call \local_campusconnect\course::create without course data');
        }
        if (empty($course->lectureID)) {
            log::add("Course resource ({$resourceid}) is missing the lectureID value - is it using an old,".
                     " unsupported data format?");
            return true; // Remove the event.
        }

        $coursedata = self::map_course_settings($course, $ecssettings);
        if (self::get_by_resourceid($resourceid, $ecssettings->get_id())) {
            log::add("Cannot create a course from resource $resourceid - it already exists.");
            return true; // The event should be removed from the queue, so we don't get this error again.
        }

        /** @var $categories course_category[] */
        $categories = self::get_categories($course, $ecssettings);
        if (empty($categories)) {
            return false; // The directory has not yet been mapped onto a category => cannot yet create the course.
        }

        list($pgroups, $pgroupmode) = parallelgroups::get_parallel_groups($course);
        if (count($pgroups) < 1) {
            $pgroups[] = array(); // Make sure there is at least one course to be created.
        }

        $courseids = array();
        $pgclass = new parallelgroups($ecssettings, $resourceid);
        foreach ($pgroups as $pgcourse) {
            $courseids[] = self::create_new_course($ecssettings, $resourceid, $course, $mid, $coursedata, $pgclass, $pgroupmode,
                                                   $pgcourse, $categories);
        }

        // Process any pre-existing course_members requests for this course.
        membership::assign_course_users($courseids, $course->lectureID);

        return true;
    }

    /**
     * Create a new course to match a given parellel group and set of categories
     * @param ecssettings $ecssettings
     * @param int $resourceid the ID of the resource on the ECS
     * @param object $course the details of the course from the ECS
     * @param int $mid the member ID that the course came from
     * @param object $coursedata the course data after mapping onto Moodle course data
     * @param parallelgroups $pgclass
     * @param int $pgroupmode the parallel groups scenario
     * @param object[] $pgcourse the parallel groups to create in this course
     * @param course_category[] $categories the categories in which to create this course
     *
     * @return int the id of the 'real' course created.
     */
    protected static function create_new_course(ecssettings $ecssettings, $resourceid, $course, $mid,
                                                $coursedata, parallelgroups $pgclass,
                                                $pgroupmode, $pgcourse, $categories) {
        global $DB;

        $internallink = 0;

        $coursedata = clone $coursedata;
        self::set_course_defaults($coursedata);
        $coursedata->fullname = $pgclass->update_course_name($coursedata->fullname, $pgroupmode, $pgcourse);

        $baseshortname = $coursedata->shortname;
        foreach ($categories as $category) {
            $coursedata->category = $category->get_categoryid();
            $num = 1;
            while ($DB->record_exists('course', array('shortname' => $coursedata->shortname))) {
                $num++;
                $coursedata->shortname = "{$baseshortname}_{$num}";
            }
            $newcourse = create_course($coursedata);

            $ins = new stdClass();
            $ins->courseid = $newcourse->id;
            $ins->resourceid = $resourceid;
            $ins->cmsid = $course->lectureID;
            $ins->ecsid = $ecssettings->get_id();
            $ins->mid = $mid;
            $ins->internallink = $internallink;
            $ins->sortorder = $category->get_order();
            $ins->directoryid = $category->get_directoryid();

            $ins->id = $DB->insert_record('local_campusconnect_crs', $ins);

            if (!$internallink) {
                $internallink = $newcourse->id; // Point all subsequent courses at the first one (the 'real' course).

                // Let the ECS server know about the created link.
                $courseurl = new course_url($ins->id);
                $courseurl->add();

                // Create any required groups for this course.
                $pgclass->update_parallel_groups($course->lectureID, $newcourse, $pgroupmode, $pgcourse);

                notification::queue_message($ecssettings->get_id(),
                                            notification::MESSAGE_COURSE,
                                            notification::TYPE_CREATE,
                                            $newcourse->id);
            }
        }

        return $internallink; // The 'real' course that was created (if any).
    }

    /**
     * Used by the ECS event processing to update courses
     * @param int $resourceid - the ID on the ECS server
     * @param ecssettings $ecssettings - the ECS being connected to
     * @param object $course - the resource data from ECS
     * @param details $transferdetails - the metadata for the resource on the ECS
     * @param participantsettings $cms - the cms (already loaded if doing full refresh)
     * @return bool true if successful
     */
    public static function update($resourceid, ecssettings $ecssettings, $course,
                                  details $transferdetails, participantsettings $cms = null) {
        global $DB;

        if (is_null($cms)) {
            $cms = participantsettings::get_cms_participant();
        }
        $mid = $transferdetails->get_sender_mid();
        $ecsid = $ecssettings->get_id();
        if (!$cms || $cms->get_mid() != $mid || $cms->get_ecs_id() != $ecsid) {
            log::add("Recieved update course ({$resourceid}) event from non-CMS participant");
            return true; // Remove the event.
        }
        if (is_array($course)) {
            log::add("Course resource ({$resourceid}) must contain a single course, not an array of courses");
            $course = reset($course);
        }
        if (empty($course)) {
            throw new coding_exception('Should not call \local_campusconnect\course::update without course data');
        }
        if (empty($course->lectureID)) {
            log::add("Course resource ({$resourceid}) is missing the lectureID value - is it using an old,".
                     " unsupported data format?");
            return true; // Remove the event.
        }

        $currcourses = self::get_by_resourceid($resourceid, $ecsid);
        if (empty($currcourses)) {
            return self::create($resourceid, $ecssettings, $course, $transferdetails);
            //throw new course_exception("Cannot update course resource $resourceid - it doesn't exist");
        }

        $currcourse = reset($currcourses);
        if ($currcourse->mid != $mid) {
            log::add("Participant $mid attempted to update resource created by participant {$currcourse->mid}");
            return true; // Remove the event.
        }

        $categories = self::get_categories($course, $ecssettings);
        if (empty($categories)) {
            return false; // The directory has not yet been mapped onto a category => cannot yet create the course.
        }

        list($pgroups, $pgroupmode) = parallelgroups::get_parallel_groups($course);
        if (count($pgroups) < 1) {
            $pgroups[] = array(); // Make sure there is at least one course to be created.
        }
        $pgclass = new parallelgroups($ecssettings, $resourceid);
        list ($pgmatched, $pgnotmatched) = $pgclass->match_parallel_groups_to_courses($course->lectureID, $pgroups,
                                                                                      $pgroupmode, $currcourse->courseid);

        // Compare the existing allocations to the new allocations.
        list($csql, $params) = $DB->get_in_or_equal(array_keys($currcourses), SQL_PARAMS_NAMED);
        $existingcategoryids = $DB->get_records_sql_menu("SELECT ccc.id, c.category
                                                            FROM {local_campusconnect_crs} ccc
                                                            JOIN {course} c ON ccc.courseid = c.id
                                                           WHERE ccc.id $csql
                                                           ORDER BY c.category", $params);
        $unchangedcategories = array();
        /** @var $newcategories course_category[] */
        $newcategories = array();
        foreach ($categories as $category) {
            $crsid = array_search($category->get_categoryid(), $existingcategoryids);
            if ($crsid !== false) {
                do {
                    // Match up all parallel courses in the same category.
                    $unchangedcategories[$crsid] = $category;
                    unset($existingcategoryids[$crsid]); // Any left in this array will be deleted.
                } while (($crsid = array_search($category->get_categoryid(), $existingcategoryids)) !== false);
            } else {
                $newcategories[] = $category;
            }
        }

        self::remove_allocations($currcourses, $existingcategoryids, $unchangedcategories, $newcategories);
        $coursedata = self::map_course_settings($course, $ecssettings);

        // Check for orphaned crs records.
        foreach ($currcourses as $key => $currcourse) {
            if (!isset($unchangedcategories[$currcourse->id])) {
                if ($currcourse->internallink != 0) {
                    // Internal link course has been deleted - can safely delete the crs record.
                    $DB->delete_records('local_campusconnect_crs', array('id' => $currcourse->id));
                    unset($currcourses[$key]);
                } else {
                    // The 'real' course has been deleted, need to recreate it.
                    // Create in the first category, which is where the real course should always be located.
                    $oldcourseid = $currcourse->courseid;
                    $category = reset($categories);
                    $coursedetails = clone $coursedata;
                    $coursedetails->category = $category->get_categoryid();
                    $baseshortname = $coursedetails->shortname;
                    $num = 1;
                    while ($DB->record_exists('course', array('shortname' => $coursedetails->shortname))) {
                        $num++;
                        $coursedetails->shortname = "{$baseshortname}_{$num}";
                    }
                    if (isset($pgmatched[$currcourse->courseid])) {
                        $coursedetails->fullname = $pgclass->update_course_name($coursedetails->fullname,
                                                                                $pgroupmode, $pgmatched[$currcourse->courseid]);
                    }
                    $newcourse = create_course($coursedetails);
                    unset($coursedetails);

                    // Update the main crs record for this entry.
                    $currcourse->courseid = $newcourse->id;
                    $DB->set_field('local_campusconnect_crs', 'courseid', $newcourse->id, array('id' => $currcourse->id));

                    // Update any courselinks to point at this course.
                    foreach ($currcourses as $crs) {
                        if ($crs->internallink == $oldcourseid) {
                            $crs->internallink = $newcourse->id;
                            $DB->set_field('local_campusconnect_crs', 'internallink', $newcourse->id, array('id' => $crs->id));
                        }
                    }

                    // Update any groups for this course.
                    if (isset($pgmatched[$oldcourseid])) {
                        $pgclass->update_parallel_groups($course->lectureID, $newcourse, $pgroupmode, $pgmatched[$oldcourseid]);
                    }
                }
            }
        }

        // Update all the existing crs records.
        foreach ($currcourses as $currcourse) {
            if (!$oldcourserecord = $DB->get_record('course', array('id' => $currcourse->courseid), 'id, shortname')) {
                throw new coding_exception("crs record {$currcourse->id} references non-existent course {$currcourse->courseid}");
            } else {
                // Course still exists - update it.
                $coursedetails = clone $coursedata;
                $coursedetails->id = $currcourse->courseid;
                $realcourseid = $currcourse->internallink ? $currcourse->internallink : $currcourse->courseid;
                if (isset($pgmatched[$realcourseid])) {
                    $coursedetails->fullname = $pgclass->update_course_name($coursedetails->fullname,
                                                                            $pgroupmode, $pgmatched[$realcourseid]);
                }
                // Avoid duplicate shortname fields.
                if ($oldcourserecord->shortname != $coursedetails->shortname) {
                    $matchshortname = '^'.preg_quote($coursedetails->shortname).'_\d+$';
                    if (!preg_match("|{$matchshortname}|", $oldcourserecord->shortname)) {
                        // Old shortname does not match the current shortname OR the current shortname + '_NN'.
                        $baseshortname = $coursedetails->shortname;
                        $num = 1;
                        while ($DB->record_exists('course', array('shortname' => $coursedetails->shortname))) {
                            $num++;
                            $coursedetails->shortname = "{$baseshortname}_{$num}";
                        }
                    } else {
                        unset($coursedetails->shortname); // Does not need updating.
                    }
                } else {
                    unset($coursedetails->shortname); // Does not need updating.
                }
                update_course($coursedetails);

                // The cms course id has changed (not sure if this should ever happen, but handle it anyway).
                if ($course->lectureID != $currcourse->cmsid) {
                    $upd = new stdClass();
                    $upd->id = $currcourse->id;
                    $upd->cmsid = $course->lectureID;
                    $DB->update_record('local_campusconnect_crs', $upd);
                }

                if ($currcourse->internallink == 0) {
                    // Let the ECS server know about the updated link.
                    $courseurl = new course_url($currcourse->id);
                    $courseurl->update();
                    notification::queue_message($ecssettings->get_id(),
                                                notification::MESSAGE_COURSE,
                                                notification::TYPE_UPDATE,
                                                $currcourse->courseid);
                }

                // Check the groups for this course.
                if (isset($pgmatched[$coursedetails->id])) {
                    $pgclass->update_parallel_groups($course->lectureID, $coursedetails, $pgroupmode,
                                                     $pgmatched[$coursedetails->id]);
                }
            }
        }

        // Add new crs records for any new categories that also need links in them.
        if (!empty($newcategories)) {
            $currcourse = reset($currcourses);
            $internallink = ($currcourse->internallink == 0) ? $currcourse->courseid : $currcourse->internallink;
            foreach ($newcategories as $newcategory) {
                $coursedata->category = $newcategory->get_categoryid();
                $baseshortname = $coursedata->shortname;
                $num = 1;
                while ($DB->record_exists('course', array('shortname' => $coursedata->shortname))) {
                    $num++;
                    $coursedata->shortname = "{$baseshortname}_{$num}";
                }
                $newcourse = create_course($coursedata);

                // Create a new crs record to redirect to the internallink course.
                $ins = new stdClass();
                $ins->courseid = $newcourse->id;
                $ins->resourceid = $resourceid;
                $ins->cmsid = $course->lectureID;
                $ins->ecsid = $ecsid;
                $ins->mid = $mid;
                $ins->internallink = $internallink;
                $ins->sortorder = $newcategory->get_order();
                $ins->directoryid = $newcategory->get_directoryid();
                $ins->id = $DB->insert_record('local_campusconnect_crs', $ins);
                $currcourses[] = $ins;
            }
        }

        // Check the 'real' course is in the first category in the list, if not, swap the course with one of the links.
        $firstcategory = reset($categories);
        $firstcategoryid = $firstcategory->get_categoryid();
        $realcourseids = array();
        foreach ($currcourses as $currcourse) {
            $realid = ($currcourse->internallink == 0) ? $currcourse->courseid : $currcourse->internallink;
            $realcourseids[$realid] = $realid;
        }
        $realcategories = $DB->get_records_list('course', 'id', $realcourseids, '', 'id, category');
        foreach ($realcategories as $realcategory) {
            if ($realcategory->category != $firstcategoryid) {
                // The 'real' course is not in the first category - find the course that is in that category and swap them.
                $params = array('resourceid' => $resourceid, 'ecsid' => $ecsid, 'firstcategoryid' => $firstcategoryid);
                $swapcourseid = $DB->get_field_sql('SELECT c.id
                                                  FROM {course} c
                                                  JOIN {local_campusconnect_crs} ccc ON c.id = ccc.courseid
                                                 WHERE ccc.resourceid = :resourceid AND ccc.ecsid = :ecsid
                                                   AND c.category = :firstcategoryid', $params, MUST_EXIST);

                $realcourse = (object)array('id' => $realcategory->id, 'category' => $firstcategoryid);
                $swapcourse = (object)array('id' => $swapcourseid, 'category' => $realcategory->category);
                $DB->update_record('course', $realcourse);
                $DB->update_record('course', $swapcourse);

                // Swap the directoryids & sortorder for these courses.
                $crs1 = $DB->get_record('local_campusconnect_crs', array('courseid' => $realcourse->id),
                                        'id, sortorder, directoryid', MUST_EXIST);
                $crs2 = $DB->get_record('local_campusconnect_crs', array('courseid' => $swapcourseid),
                                        'id, sortorder, directoryid', MUST_EXIST);
                $tempid = $crs1->id;
                $crs1->id = $crs2->id;
                $crs2->id = $tempid;
                $DB->update_record('local_campusconnect_crs', $crs1);
                $DB->update_record('local_campusconnect_crs', $crs2);
            }
        }

        // Create new courses for parallel groups that didn't exist before.
        if ($pgnotmatched) {
            $courseids = array();
            foreach ($pgnotmatched as $pgcourse) {
                $courseids[] = self::create_new_course($ecssettings, $resourceid, $course, $mid, $coursedata, $pgclass,
                                                       $pgroupmode, $pgcourse, $categories);
            }
            // Not calling \local_campusconnect\membership::assign_course_users here as this will have already been
            // processed for this cmscourseid at the point when the 'course' resource was first created OR at the point
            // when the 'course_member' resource was first created (if this was after the 'course' resource was created).
            // There should be no outstanding 'course_member' resource requests for this course.
        }

        return true;
    }

    /**
     * Used by the ECS event processing to delete courses
     * @param int $resourceid - the ID on the ECS server
     * @param ecssettings $ecssettings - the ECS being connected to
     * @return bool true if successful
     */
    public static function delete($resourceid, ecssettings $ecssettings) {
        global $DB;

        $currcourses = self::get_by_resourceid($resourceid, $ecssettings->get_id());
        foreach ($currcourses as $currcourse) {
            if ($currcourse->internallink == 0) {
                // Do not actually delete the 'real' course.
                notification::queue_message($ecssettings->get_id(),
                                            notification::MESSAGE_COURSE,
                                            notification::TYPE_DELETE,
                                            $currcourse->courseid);

                // Leave the course_url code to delete the record once it has informed the ECS.
                $courseurl = new course_url($currcourse->id);
                $courseurl->delete();
            } else {
                // Delete the internal links.
                $DB->delete_records('local_campusconnect_crs', array('id' => $currcourse->id));
                delete_course($currcourse->courseid, false);
            }
        }

        return true;
    }

    /**
     * Update all courses from the ECS
     * @param ecssettings $ecssettings
     * @return object containing: ->created - array of created resource ids
     *                            ->updated - array of updated resource ids
     *                            ->deleted - array of deleted resource ids
     */
    public static function refresh_from_ecs(ecssettings $ecssettings) {
        global $DB;

        $ret = (object)array('created' => array(), 'updated' => array(), 'deleted' => array());

        if (!self::enabled()) {
            return $ret; // Course creation disabled.
        }

        // Get the CMS participant.
        /** @var $cms participantsettings */
        if (!$cms = participantsettings::get_cms_participant()) {
            return $ret;
        }
        if ($cms->get_ecs_id() != $ecssettings->get_id()) {
            // Not refreshing the ECS that the CMS is attached to.
            return $ret;
        }

        // Get full list of courselinks from this ECS.
        $courses = $DB->get_records('local_campusconnect_crs', array('ecsid' => $cms->get_ecs_id(), 'mid' => $cms->get_mid()),
                                    '', 'DISTINCT resourceid');

        // Get full list of courselink resources shared with us.
        $connect = new connect($ecssettings);
        $servercourses = $connect->get_resource_list(event::RES_COURSE);

        // Go through all the links from the server and compare to what we have locally.
        foreach ($servercourses->get_ids() as $resourceid) {
            $details = $connect->get_resource($resourceid, event::RES_COURSE,
                                              connect::CONTENT);
            $transferdetails = $connect->get_resource($resourceid, event::RES_COURSE,
                                                      connect::TRANSFERDETAILS);

            // Check if we already have this locally.
            if (isset($courses[$resourceid])) {
                self::update($resourceid, $ecssettings, $details, $transferdetails, $cms);
                $ret->updated[] = $resourceid;
                unset($courses[$resourceid]); // So we can delete anything left in the list at the end.
            } else {
                // We don't already have this course.
                if (empty($details)) {
                    continue; // This probably shouldn't occur, but we're just going to ignore it.
                }

                self::create($resourceid, $ecssettings, $details, $transferdetails, $cms);
                $ret->created[] = $resourceid;
            }
        }

        // Delete any courses still in our local list (they have either been deleted remotely, or they are from
        // participants we no longer import course links from).
        foreach ($courses as $course) {
            self::delete($course->resourceid, $ecssettings);
            $ret->deleted[] = $course->resourceid;
        }

        return $ret;
    }

    /**
     * Sort all directories based on their allocation sortorder
     * @param int $rootdir the CampusConnect directory to find courses within
     * @return bool true if there were changes to the course table (so fix_course_sortorder is needed)
     */
    public static function sort_courses($rootdir) {
        global $DB;

        // Find all the allocated courses within this root directory with a sortorder, ordered by subdirectory + sortorder.
        $sql = "SELECT crs.*, c.sortorder AS coursesortorder
                  FROM {local_campusconnect_crs} crs
                  JOIN {local_campusconnect_dir} dir ON crs.directoryid = dir.directoryid
                  JOIN {course} c ON crs.courseid = c.id
                 WHERE crs.sortorder <> 0 AND dir.rootid = ?
              ORDER BY crs.directoryid, crs.sortorder, c.sortorder"; // Use course sortorder, if CMS sort order is the same.
        $crs = $DB->get_records_sql($sql, array($rootdir));

        // Check that the course sortorder increases as we go through the sorted list within each subdirectory.
        $changes = false;
        $lastorder = -1;
        $lastdir = -1;
        foreach ($crs as $cr) {
            if ($cr->directoryid != $lastdir) {
                // Onto the next subdirectory.
                $lastdir = $cr->directoryid;
                $lastorder = -1;
            } else {
                if ($cr->coursesortorder <= $lastorder) {
                    // Found a course with an out-of-sequence sortorder => fix it.
                    $DB->set_field('course', 'sortorder', $lastorder + 1, array('id' => $cr->courseid));
                    $changes = true;
                    $lastorder = $lastorder + 1;
                } else {
                    $lastorder = $cr->coursesortorder;
                }
            }
        }

        return $changes;
    }

    /**
     * Get the course db record from its resourceid and ecsid
     * @param int $resourceid
     * @param int $ecsid
     * @return mixed false | object[] - may be multiple if the same course is mapped into multiple locations
     */
    public static function get_by_resourceid($resourceid, $ecsid) {
        global $DB;
        $params = array('resourceid' => $resourceid, 'ecsid' => $ecsid);
        return $DB->get_records('local_campusconnect_crs', $params);
    }

    /**
     * Returns the redirect URL if this is an internal link to the real course.
     * @param int $courseid
     * @return mixed moodle_url | false - the url to redirect to
     */
    public static function check_redirect($courseid) {
        global $DB;
        if (!$course = $DB->get_record('local_campusconnect_crs', array('courseid' => $courseid))) {
            return false;
        }
        if ($course->internallink == 0) {
            return false; // This is the 'real' course - no redirect needed.
        }
        return new moodle_url('/course/view.php', array('id' => $course->internallink));
    }

    /**
     * Given a list of courseids from the CMS, return the Moodle course ids that these map onto
     * @param int[] $cmscourseids
     * @return array[] [ CMS courseid => Moodle courseid[], All moodle courseid[] ]
     */
    public static function get_courseids_from_cmscourseids(array $cmscourseids) {
        global $DB;

        if (empty($cmscourseids)) {
            return array();
        }

        list($csql, $params) = $DB->get_in_or_equal($cmscourseids);

        $recs = $DB->get_records_select('local_campusconnect_crs', "cmsid  $csql AND internallink = 0", $params,
                                        'id', 'id, cmsid, courseid');
        $mapping = array();
        $courseids = array();
        foreach ($recs as $rec) {
            if (!isset($mapping[$rec->cmsid])) {
                $mapping[$rec->cmsid] = array();
            }
            $mapping[$rec->cmsid][] = $rec->courseid;
            $courseids[] = $rec->courseid;
        }

        return array($mapping, $courseids);
    }

    /**
     * Given a list of Moodle courseids, return the CMS course ids that these map onto
     * @param int[] $courseids
     * @return int[] mapping CMS courseid => Moodle courseid
     */
    public static function get_cmscourseids_from_courseids(array $courseids) {
        global $DB;

        if (empty($courseids)) {
            return array();
        }

        list($csql, $params) = $DB->get_in_or_equal($courseids);
        return $DB->get_records_select_menu('local_campusconnect_crs', "courseid $csql AND internallink = 0", $params,
                                            '', 'courseid, cmsid');
    }

    /**
     * Generate the Moodle course metadata, based on the metadata details from the ECS server
     * @param object $course
     * @param ecssettings $ecssettings
     * @return object
     */
    protected static function map_course_settings($course, ecssettings $ecssettings) {
        $metadata = new metadata($ecssettings, false);
        $coursedata = $metadata->map_remote_to_course($course);
        $coursedata->summaryformat = FORMAT_HTML;

        return $coursedata;
    }

    /**
     * Updates the course object to include suitable defaults, where no alternatives are specified
     * @param object $course data object to be updated
     */
    protected static function set_course_defaults(&$course) {
        $config = get_config('moodlecourse');

        $params = array(
            'format', 'numsections', 'hiddensections', 'newsitems', 'showgrades', 'showreports', 'maxbytes',
            'groupmode', 'groupmodeforce', 'visible', 'lang', 'enablecompletion', 'completionstartonenrol'
        );

        foreach ($params as $param) {
            if (!isset($course->$param) && isset($config->$param)) {
                $course->$param = $config->$param;
            }
        }
        if (!\completion_info::is_enabled_for_site()) {
            $course->enablecompletion = 0;
            $course->completionstartonenrol = 0;
        }
    }

    /**
     * Use the course filtering rules or the 'allocation' section of the course resource to determine the category ID
     * to create the course in.
     * The category will be created, if required.
     * @param object $course
     * @param ecssettings $ecssettings
     * @return course_category[] empty if the directory is not yet mapped, so the course cannot be created
     */
    protected static function get_categories($course, ecssettings $ecssettings) {
        // Use course filtering rules, if enabled.
        if (filtering::enabled()) {
            $catids = filtering::get_categories($course, $ecssettings);
            if (empty($catids)) {
                throw new course_exception(get_string('filternocategories', 'local_campusconnect'));
            }
            $ret = array();
            foreach ($catids as $catid) {
                $ret[] = new course_category($catid);
            }
            return $ret;
        }

        // No course filtering rules - use the 'allocations' specified by the CMS.
        if (empty($course->allocations)) {
            debugging("Warning - course request without 'allocations' details - using default import category");
            return $ecssettings->get_import_category();
        }

        $ret = array();
        foreach ($course->allocations as $allocation) {
            if ($catid = directorytree::get_category_for_course($allocation->parentID)) {
                $order = isset($allocation->order) ? $allocation->order : 0;
                $ret[] = new course_category($catid, $order, $allocation->parentID);
            }
        }

        return $ret;
    }

    /**
     * Internal function that deletes internal course links from categories that no longer contain a link to that course
     * Where possible, courses are moved into new categories, instead of deleting them. 'Real' courses are always retained
     * (and moved to new categories, if required).
     * @param object[] $currcourses
     * @param int[] $removecategoryids mapping local_campusconnect_crs.id => categoryid
     * @param course_category[] $unchangedcategories
     * @param course_category[] $newcategories
     * @throws coding_exception
     */
    protected static function remove_allocations(&$currcourses, &$removecategoryids, &$unchangedcategories, &$newcategories) {
        global $DB;

        if (empty($removecategoryids)) {
            return; // Nothing to change.
        }

        if (empty($newcategories) && empty($unchangedcategories)) {
            throw new coding_exception('\local_campusconnect\course::remove_allocations - unchangedcategories and'.
                                       " newcategories should never both be empty");
        }

        $firstnewcategory = false;
        // Make sure the 'real' course continues to exist - move it to a different category,
        // if no longer mapped to its current location.
        foreach ($removecategoryids as $rcrsid => $rcatid) {
            $currcourse = $currcourses[$rcrsid];
            if ($currcourse->internallink == 0) { // We are trying to remove the 'real' course - instead move it.
                if (!empty($newcategories) || $firstnewcategory) {
                    // Move it into the newly-mapped category.
                    /** @var $firstnewcategory course_category */
                    if ($firstnewcategory === false) {
                        // Only one 'real course' per parallel course, so map all onto the first 'new category'.
                        $firstnewcategory = array_shift($newcategories);
                    }
                    $DB->set_field('course', 'category', $firstnewcategory->get_categoryid(), array('id' => $currcourse->courseid));
                    // Update the directoryid / sortorder for this course.
                    $upd = new stdClass();
                    $upd->id = $currcourse->id;
                    $upd->directoryid = $firstnewcategory->get_directoryid();
                    $upd->sortorder = $firstnewcategory->get_order();
                    $DB->update_record('local_campusconnect_crs', $upd);

                    // Make sure this does not get 'cleaned up' later on.
                    $currcourse->directoryid = $upd->directoryid;
                    $currcourse->sortorder = $upd->sortorder;
                    $unchangedcategories[$currcourse->id] = $firstnewcategory;
                } else {
                    // No newly-mapped categories, so will need to move it into an existing category.
                    $keys = array_keys($unchangedcategories);
                    $removecrsid = reset($keys);
                    $updatecategory = array_shift($unchangedcategories);

                    if ($currcourses[$removecrsid]->internallink == 0) {
                        throw new coding_exception("Attempting to replace one 'real course' with another - this should not happen");
                    }

                    $DB->set_field('course', 'category', $updatecategory->get_categoryid(), array('id' => $currcourse->courseid));
                    // Update the directoryid / sortorder for this course.
                    $upd = new stdClass();
                    $upd->id = $currcourse->id;
                    $upd->directoryid = $updatecategory->get_directoryid();
                    $upd->sortorder = $updatecategory->get_order();
                    $DB->update_record('local_campusconnect_crs', $upd);
                    // Put it into the 'unchanged' array, so it doesn't get duplicated by the 'orphanded courses' check.
                    $unchangedcategories[$currcourse->id] = $updatecategory;

                    // The existing course (which was an internal link) is no longer needed - delete it and the crs record.
                    $removecourseid = $currcourses[$removecrsid]->courseid;
                    delete_course($removecourseid, false);
                    $DB->delete_records('local_campusconnect_crs', array('id' => $removecrsid));
                    unset($currcourses[$removecrsid]);
                }
                unset($removecategoryids[$rcrsid]);
            }
        }

        // We are trying to remove some internal links and create new internal links - instead, move as many as possible
        // to new categories.
        /** @var $currentnewcat course_category */
        $currentnewcat = null;
        $currentcatid = null;
        foreach ($removecategoryids as $rcrsid => $rcatid) {
            $currcourse = $currcourses[$rcrsid];
            if ($currentcatid == $rcatid) {
                // A parallel course in the same category - move to the new category as well.
                $DB->set_field('course', 'category', $currentnewcat->get_categoryid(), array('id' => $currcourse->courseid));
            } else if (!empty($newcategories)) {
                // There is a newly-mapped category to move this internal link into.
                $currentnewcat = array_shift($newcategories);
                $currentcatid = $rcatid;
                $DB->set_field('course', 'category', $currentnewcat->get_categoryid(), array('id' => $currcourse->courseid));
            } else {
                // No newly-mapped category => just remove the course completely.
                delete_course($currcourse->courseid, false);
                $DB->delete_records('local_campusconnect_crs', array('id' => $rcrsid));
                unset($currcourses[$rcrsid]);
            }
        }
    }
}
