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
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Looks after parallel groups - parsing them out of the data from the ECS, matching them up to existing parallel groups
 * and creating the right Moodle groups for them.
 */
class parallelgroups {
    // Parallel group scenarios.
    /** Groups created, but mapped onto one course/group */
    const PGROUP_NONE = 0;
    /** Groups mapped onto groups in a single course */
    const PGROUP_SEPARATE_GROUPS = 1;
    /** Groups mapped onto single groups in multiple courses */
    const PGROUP_SEPARATE_COURSES = 2;
    /** One course per lecturer, one course group per course */
    const PGROUP_SEPARATE_LECTURERS = 3;

    /**
     * @var ecssettings
     */
    protected $ecssettings;
    /**
     * @var int
     */
    protected $resourceid;

    /**
     * @param ecssettings $ecssettings
     * @param int $resourceid
     */
    public function __construct(ecssettings $ecssettings, $resourceid) {
        $this->ecssettings = $ecssettings;
        $this->resourceid = $resourceid;
    }

    /**
     * Extract the details of the courses/groups to create to satisfy the parallel groups scenario.
     * Note: Internal function - public to allow for unit testing.
     * @param stdClass $course the course details from the ECS
     * @return array [ $parallelgroups, $scenario] - where $parallelgroups is an array as follows:
     *          [ [$group1, $group2], [$group3], [$group4] ] - outer array represents courses,
     *                                                         inner array represents groups within courses
     *          Courses with only a single group should be created with NO moodle groups
     *          If the $scenario is PGROUP_NONE, no groups should be created
     *          If the $scenario is PGROUP_ONE_COURSE, parallel group records should be created, but no Moodle groups
     *          Each group object contains: $id, $title, $comment, $lecturer (the first lecturer listed)
     */
    public static function get_parallel_groups($course) {
        if (!empty($course->groupScenario)) {
            $scenario = $course->groupScenario;
        } else {
            $scenario = self::PGROUP_NONE;
        }

        $parallelgroups = self::get_parallel_group_internal($course);

        switch ($scenario) {
            case self::PGROUP_NONE:
            case self::PGROUP_SEPARATE_GROUPS:
                $courses = array($parallelgroups);
                break;

            case self::PGROUP_SEPARATE_COURSES:
                $courses = array();
                foreach ($parallelgroups as $key => $group) {
                    $courses[] = array($key => $group);
                }
                break;

            case self::PGROUP_SEPARATE_LECTURERS:
                $courses = array();
                foreach ($parallelgroups as $pgroup) {
                    $lecturer = $pgroup->lecturer;
                    if (empty($lecturer)) {
                        $lecturer = 0;
                    }
                    if (!isset($courses[$lecturer])) {
                        $courses[$lecturer] = array();
                    }
                    $courses[$lecturer][] = $pgroup;
                }
                break;

            default:
                debugging("Unknown parallel groups scenario: {$scenario}");
                $courses = array();
                $scenario = self::PGROUP_NONE;
        }

        return array($courses, $scenario);
    }

    /**
     * Extract the parallel groups from the course data
     * @param stdClass $course the course data from the ECS
     * @return stdClass[] - groupid => group details (with 'lecturers' flattened to the name of the first lecturer)
     */
    protected static function get_parallel_group_internal($course) {
        if (!isset($course->groups)) {
            return array();
        }

        $groupnum = 0;
        $groups = array();
        foreach ($course->groups as $group) {
            $details = new stdClass();
            $details->cmscourseid = $course->lectureID;
            $details->groupnum = $groupnum;
            $details->title = !empty($group->title) ? $group->title : get_string('groupname', 'local_campusconnect', $groupnum);
            $details->comment = isset($group->comment) ? $group->comment : null;
            if (isset($group->lecturers)) {
                // Only use the first lecturer name => map all groups starting with same lecturer onto same course
                // (PGROUP_SEPARATE_LECTURERS).
                $lecturer = reset($group->lecturers);
                $details->lecturer = $lecturer->firstName.' '.$lecturer->lastName;
            } else {
                $details->lecturer = '';
            }
            $groups[] = $details;
            $groupnum++;
        }

        return $groups;
    }

    /**
     * Given the details of the parallel groups and pg roles for a user, return a list of courses and groups to
     * enrol this user into.
     * @param string[] $pgroups mapping groupnum => role to assign
     * @param string $cmscourseid
     * @param int[] $defaultcourseids all courseids associated with the cms course the user is enroling into
     * @param string $defaultrole the role to be assigned to the user
     * @return array
     */
    public static function get_groups_for_user($pgroups, $cmscourseid, $defaultcourseids, $defaultrole) {
        global $DB;

        static $groupcache = array();
        if (PHPUNIT_TEST) {
            $groupcache = array(); // Static cache breaks unit tests when more than one test uses the same cmscourseid.
        }

        // User enroling in parallel groups - generate a list of all the courses they need to enrol in.
        $ret = array();
        foreach ($pgroups as $groupnum => $grouprole) {
            if (!isset($groupcache[$cmscourseid])) {
                $groupcache[$cmscourseid] = $DB->get_records('local_campusconnect_pgroup', array('cmscourseid' => $cmscourseid),
                                                             '', 'groupnum, courseid, groupid');
            }
            $coursegroups = $groupcache[$cmscourseid];
            if (isset($coursegroups[$groupnum])) {
                $ret[] = (object)array(
                    'courseid' => $coursegroups[$groupnum]->courseid,
                    'role' => ($grouprole >= 0) ? $grouprole : $defaultrole,
                    'groupid' => $coursegroups[$groupnum]->groupid,
                    'groupnum' => $groupnum
                );
                if (!in_array($coursegroups[$groupnum]->courseid, $defaultcourseids)) {
                    debugging("Expected {$coursegroups[$groupnum]->courseid}, the course for parallel group".
                              " {$groupnum}, to be in the list of courses: (".implode(', ', $defaultcourseids).")");
                }
            }
        }

        // No parallel groups found - just enrol into the first course with the default role.
        if (empty($ret)) {
            $ret = array(
                (object)array(
                    'courseid' => reset($defaultcourseids),
                    'role' => $defaultrole,
                    'groupid' => 0,
                    'groupnum' => 0,
                )
            );
        }

        return $ret;
    }

    /**
     * Attempts to organise the parallel groups based on the Moodle courseids that the groups have already been mapped onto.
     * Any groups that cannot be mapped onto an existing course are mapped on to an existing course are returned separately.
     * @param string $cmscourseid
     * @param stdClass[] $pgroups
     * @param int $pgroupsmode the current parallel groups mode
     * @param int $firstcourseid the ID of the first existing course (needed if there are no exiting pgroups found)
     * @return array [ $matched, $notmatched ] - $matched = associative array $courseid => array of group details
     *                                           $notmatched = array of array of group details
     */
    public function match_parallel_groups_to_courses($cmscourseid, $pgroups, $pgroupsmode, $firstcourseid) {
        global $DB;

        $matched = array();
        $notmatched = array();
        $existing = $DB->get_records('local_campusconnect_pgroup', array(
            'ecsid' => $this->ecssettings->get_id(),
            'resourceid' => $this->resourceid,
            'cmscourseid' => $cmscourseid
        ),
                                     '', 'id, cmscourseid, groupnum, courseid');
        if (empty($existing)) {
            // This probably means we've just switched from PGROUP_NONE to one of the scenarios. Assume that the existing
            // course matches the first pgcourse.
            $pgcourse = array_shift($pgroups);
            return array(array($firstcourseid => $pgcourse), $pgroups);
        }

        foreach ($pgroups as $pcourse) {
            // Go through the groups in each parallel course and see if we can match them with already-instantiated versions
            // (based on the group 'ID' from the CMS).
            foreach ($pcourse as $pg) {
                $foundgroup = null;
                foreach ($existing as $existinggroup) {
                    if ($pg->groupnum == $existinggroup->groupnum) {
                        $foundgroup = $existinggroup;
                        break;
                    }
                }
                if ($foundgroup) {
                    $courseid = $foundgroup->courseid;
                    if (!isset($matched[$courseid])) {
                        // If courseid might already be 'taken' if switching from one group per pgroup to one course
                        // per pgroup, so only match up the first time the courseid is found (others will be used to
                        // create new courses).
                        $matched[$courseid] = $pcourse;
                        continue 2; // Move on to the next course in the parallel groups.
                    }
                }
            }
            // None of the existing parallel groups in Moodle matched any of the parallel groups in this course.
            $notmatched[] = $pcourse;
        }

        return array($matched, $notmatched);
    }

    /**
     * Compare the parallel groups already existing in the course and update to match the current scenario / groups
     * @param string $cmscourseid
     * @param stdClass $course
     * @param int $pgroupmode
     * @param array $pcourse
     * @throws coding_exception
     */
    public function update_parallel_groups($cmscourseid, stdClass $course, $pgroupmode, $pcourse) {
        global $DB;

        if ($pgroupmode == self::PGROUP_SEPARATE_COURSES) {
            if (count($pcourse) > 1) {
                throw new coding_exception("With 'separate groups' mode, only one group should be passed in to each course");
            }
        }

        $sql = "SELECT pg.id, pg.grouptitle, pg.cmscourseid, pg.groupnum, pg.groupid, g.id AS groupexists
                  FROM {local_campusconnect_pgroup} pg
                  LEFT JOIN {groups} g ON g.id = pg.groupid
                 WHERE pg.ecsid = :ecsid AND pg.resourceid = :resourceid
                   AND pg.courseid = :courseid AND pg.cmscourseid = :cmscourseid";
        $params = array(
            'ecsid' => $this->ecssettings->get_id(), 'resourceid' => $this->resourceid,
            'courseid' => $course->id, 'cmscourseid' => $cmscourseid
        );
        $existing = $DB->get_records_sql($sql, $params);

        unset($params['courseid']);
        $existingallcourses = $DB->get_records('local_campusconnect_pgroup', $params, '',
                                               'id, groupnum, courseid, groupid, grouptitle');

        $ins = new stdClass();
        $ins->ecsid = $this->ecssettings->get_id();
        $ins->resourceid = $this->resourceid;
        $ins->courseid = $course->id;
        $ins->cmscourseid = $cmscourseid;

        // Create each of the parallel groups requested.
        $creategroup = ($pgroupmode != self::PGROUP_NONE) && (count($pcourse) > 1);
        foreach ($pcourse as $pg) {
            /** @var stdClass $foundgroup */
            $foundgroup = null;
            foreach ($existing as $existinggroup) {
                if ($existinggroup->groupnum == $pg->groupnum) {
                    $foundgroup = $existinggroup;
                    break;
                }
            }
            if ($foundgroup) {
                // The pgroup is already mapped onto this course - update it if needed.
                $upd = new stdClass();
                if ($creategroup && is_null($foundgroup->groupexists)) {
                    // The Moodle group does not exist/has been deleted - (re)create it..
                    $upd->groupid = $this->create_or_update_group($course, $pg);
                    $upd->grouptitle = $pg->title;
                } else if (!$creategroup && $foundgroup->groupid) {
                    // Not creating groups but there is an existing group - remove the reference to the group.
                    $upd->groupid = 0;
                    $upd->grouptitle = $pg->title;
                } else if ($foundgroup->grouptitle != $pg->title) {
                    // Group title has changed - update it (and the Moodle group title as well, if required).
                    $upd->grouptitle = $pg->title;
                    if ($creategroup) {
                        $this->create_or_update_group($course, $pg, $foundgroup->groupid);
                    }
                } else {
                    continue; // No changes, so no need to update the record.
                }

                // Update pgroup record with the changes.
                $upd->id = $foundgroup->id;
                $DB->update_record('local_campusconnect_pgroup', $upd);

            } else {
                /** @var stdClass $foundallgroup */
                $foundallgroup = null;
                foreach ($existingallcourses as $existinggroup) {
                    if ($existinggroup->groupnum == $pg->groupnum) {
                        $foundallgroup = $existinggroup;
                        break;
                    }
                }

                if ($foundallgroup) {
                    // The group exists, but is in a different course (probably because the parallel groups scenario has changed).
                    $upd = new stdClass();
                    $upd->id = $foundallgroup->id;
                    $upd->courseid = $course->id;
                    $upd->grouptitle = $pg->title;
                    if ($creategroup) {
                        $upd->groupid = $this->create_or_update_group($course, $pg);
                    } else {
                        $upd->groupid = 0;
                    }
                    $DB->update_record('local_campusconnect_pgroup', $upd);

                } else {
                    // The pgroup does not yet exist.
                    if ($DB->record_exists('local_campusconnect_pgroup', array(
                        'cmscourseid' => $cmscourseid,
                        'groupnum' => $pg->groupnum
                    ))
                    ) {
                        debugging("Group already exists with cmscourseid: {$cmscourseid} and groupnum: {$pg->groupnum}".
                                  " - skipping creation of new group");
                    } else {
                        $ins->groupnum = $pg->groupnum;
                        $ins->grouptitle = $pg->title;
                        if ($creategroup) {
                            $ins->groupid = $this->create_or_update_group($course, $pg);
                        } else {
                            $ins->groupid = 0;
                        }
                        $DB->insert_record('local_campusconnect_pgroup', $ins);
                    }
                }
            }
        }

        // No deletion of unwanted groups.
    }

    /**
     * Create a new Moodle group (if it doesn't exist) or update it (if it does)
     * @param stdClass $course details of the Moodle course to create the group in
     * @param stdClass $pgroup details of the parallel group to create in this course
     * @param int $id optional - if set, the group is updated, otherwise the group is created
     * @return int the ID of the newly created group
     */
    public function create_or_update_group($course, $pgroup, $id = null) {
        global $CFG;
        require_once($CFG->dirroot.'/group/lib.php');
        $data = new stdClass();
        $data->courseid = $course->id;
        $data->name = $pgroup->title;
        if (isset($pgroup->comment) && !is_null($pgroup->comment)) {
            $data->description = $pgroup->comment;
            $data->descriptionformat = FORMAT_PLAIN;
        }
        if ($id) {
            $data->id = $id;
            groups_update_group($data);
        } else {
            $data->id = groups_create_group($data);
        }
        return $data->id;
    }

    /**
     * Add the name of the group or lecturer to the course fullname
     * @param string $coursename the base course name
     * @param int $pgroupmode the parallel groups scenario in use
     * @param stdClass[] $pcourse the details of the parallel groups to create in this course
     * @return string the new fullname for the course
     */
    public function update_course_name($coursename, $pgroupmode, $pcourse) {
        $extra = '';
        if ($pgroupmode == self::PGROUP_SEPARATE_COURSES) {
            $pgroup = reset($pcourse);
            if (!empty($pgroup->title)) {
                $extra = " ({$pgroup->title})";
            }
        } else if ($pgroupmode == self::PGROUP_SEPARATE_LECTURERS) {
            $pgroup = reset($pcourse);
            if (!empty($pgroup)) {
                $extra = " ({$pgroup->lecturer})";
            }
        }
        return $coursename.$extra;
    }
}
