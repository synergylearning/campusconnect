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
 * Tests for the course request processing for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2013 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\course;
use local_campusconnect\courselink;
use local_campusconnect\details;
use local_campusconnect\directory;
use local_campusconnect\directorytree;
use local_campusconnect\ecssettings;
use local_campusconnect\event;
use local_campusconnect\member_personid;
use local_campusconnect\membership;
use local_campusconnect\parallelgroups;
use local_campusconnect\participantsettings;

/**
 * These tests assume the following set up is already in place with
 * your ECS server:
 * - ECS server running on localhost:3000
 * - participant ids 'unittest1', 'unittest2' and 'unittest3' created
 * - participants are named 'Unit test 1', 'Unit test 2' and 'Unit test 3'
 * - all 3 participants have been added to a community called 'unittest'
 * - none of the participants are members of any other community
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class local_campusconnect_coursemembers_test
 * @group local_campusconnect
 */
class local_campusconnect_coursemembers_test extends advanced_testcase {
    /** @var ecssettings[] $settings */
    protected $settings = array();
    protected $mid = array();
    /** @var directory[] $directory */
    protected $directory = array();
    /** @var details $transferdetails */
    protected $transferdetails = null;
    protected $users = array();

    protected $usernames = array('user1', 'user2', 'user3', 'user4', 'user5');

    protected $directorydata = array(1001 => 'dir1', 1002 => 'dir2', 1003 => 'dir3');
    protected $coursedata = '
    {
        "lectureID": "abc_1234",
        "title": "Test course creation",
        "organisation": "Synergy Learning",
        "organisationalUnits":
        [
            {
                "id": "org01",
                "title": "Org1 title"
            },
            {
                "id": "org02",
                "title": "Org2 title"
            }
        ],
        "term": "Summer 2013",
        "termID": "20131",
        "lectureType": "online",
        "hoursPerWeek": 2,
        "groupScenario": 0,
        "degreeProgrammes":
        [
            {
                "id": "programmeID",
                "title": "Test programme",
                "code": "pr21",
                "courseUnitYearOfStudy":
                {
                    "from": 5,
                    "to": 8
                }
            }
        ],
        "allocations":
        [
            {
                "parentID": "id1001",
                "order": 6
            },
            {
                "parentID": "id1002",
                "order": 9
            }
        ],
        "comment1": "This just a test",
        "recommendedReading": "Lord of the Rings",
        "prerequisites": "ability to breathe",
        "lectureAssessmentType": "guessing",
        "lectureTopics": "things + other stuff",
        "linkToCurriculumt": "none",
        "targetAudiences":
        [
            "everyone"
        ],
        "links":
        [
            {
                "href": "http://en.wikipedia.org",
                "title": "Wikipedia"
            }
        ],
        "groups":
        [
            {
                "title": "Test Group1",
                "comment": "This is a group",
                "lecturers":
                [
                    {
                        "firstName": "Humphrey",
                        "lastName": "Bogart"
                    },
                    {
                        "firstName": "Sam",
                        "lastName": "Spade"
                    }
                ],
                "maxParticipants": 20
            },
            {
                "title": "Test Group2",
                "lecturers":
                [
                    {
                        "firstName": "Humphrey",
                        "lastName": "Bogart"
                    }
                ]
            }
        ],
        "modules":
        [
            {
                "id": "mod01",
                "title": "First module",
                "number": 5,
                "credits": 20,
                "hoursPerWeek": 10,
                "duration": 5,
                "cycle": "weekly"
            }
        ]
    }
    ';

    // Reminder: role values are - 0 = lecturer (editingteacher); 1 = learner/student (student); 2 = assistant (teacher).
    protected $coursemembers = '
    {
        "lectureID": "abc_1234",
        "members":
        [
            {
                "personID": "user1",
                "role": 2,
                "groups":
                [
                    {
                        "num": 0,
                        "role": 0
                    }
                ]
            },
            {
                "personID": "user2",
                "role": 1
            },
            {
                "personID": "user3",
                "role": 0,
                "groups":
                [
                    {
                        "num": 0,
                        "role": 1
                    },
                    {
                        "num": 1,
                        "role": 2
                    },
                    {
                        "num": 2,
                        "role": 1
                    }
                ]
            },
            {
                "personID": "user4",
                "groups":
                [
                    {
                        "num": 1
                    }
                ]
            }
        ]
    }
    ';

    public function setUp() {
        global $DB;

        if (defined('SKIP_CAMPUSCONNECT_COURSEMEMBERS_TESTS')) {
            $this->markTestSkipped('Skipping connect tests, to save time');
        }

        $this->resetAfterTest();

        // Create the connections for testing.
        $names = array(1 => 'unittest1', 2 => 'unittest2', 3 => 'unittest3');
        foreach ($names as $key => $name) {
            $category = $this->getDataGenerator()->create_category(array('name' => 'import'.$key));
            $ecs = new ecssettings();
            $ecs->save_settings(array(
                                    'url' => 'http://localhost:3000',
                                    'auth' => ecssettings::AUTH_NONE,
                                    'ecsauth' => $name,
                                    'importcategory' => $category->id,
                                    'importrole' => 'student',
                                ));
            $this->settings[$key] = $ecs;
            $this->mid[$key] = $key * 10; // Real MID not needed, as no actual connection is created.
        }

        // Set participant 1 as the CMS for participant 2.
        $part = (object)array(
            'ecsid' => $this->settings[2]->get_id(),
            'mid' => $this->mid[1],
            'export' => 0,
            'import' => 1,
            'importtype' => participantsettings::IMPORT_CMS,
        );
        $DB->insert_record('local_campusconnect_part', $part);
        participantsettings::get_cms_participant(true); // Reset the cached 'cms participant' value.

        // Create the directories for the courses + map on to categories.
        $dirtree = new directorytree();
        $dirtree->create(1000, 'idroot', 'Dir root', $this->settings[2]->get_id(), $this->mid[1]);
        foreach ($this->directorydata as $id => $name) {
            $dirid = 'id'.$id;
            $dir = new directory();
            $dir->create($id, 'idroot', $dirid, 'idroot', $name, 1);
            $this->directory[] = $dir;
        }
        $category = $this->getDataGenerator()->create_category(array('name' => 'category_tree'));
        $dirtree->map_category($category->id);
        $dirtree->create_all_categories();
        // Reload the directory objects after creating the categories for them.
        foreach ($this->directory as $key => $dir) {
            $this->directory[$key] = $dirtree->get_directory($dir->get_directory_id());
        }

        // Create some fake transfer details for the requests.
        $this->transferdetails = new details((object)array(
            'url' => 'fakeurl',
            'receivers' => array(0 => (object)array('itsyou' => 1, 'mid' => $this->mid[2])),
            'senders' => array(0 => (object)array('mid' => $this->mid[1])),
            'owner' => (object)array('itsyou' => 0),
            'content_type' => event::RES_COURSE
        ));

        // Create some users to be enrolled in the course.
        foreach ($this->usernames as $username) {
            // Statslib_test has an annoying habit of creating 'user1' + 'user2', even when not running those tests.
            if (!$user = $DB->get_record('user', array('username' => $username))) {
                $user = $this->getDataGenerator()->create_user(array('username' => $username, 'email' => $username.'@example.com'));
            }
            $this->users[] = $user;
        }

        // Enable the campusconnect enrol plugin.
        $enabled = enrol_get_plugins(true);
        $enabled['campusconnect'] = true;
        $enabled = array_keys($enabled);
        set_config('enrol_plugins_enabled', implode(',', $enabled));

        // Set up the default role mappings.
        $mappings = array(
            membership::ROLE_LECTURER => 'editingteacher',
            membership::ROLE_STUDENT => 'student',
            membership::ROLE_ASSISTANT => 'teacher'
        );
        $roles = get_all_roles();
        foreach ($mappings as $ccrole => $moodlerole) {
            foreach ($roles as $role) {
                if ($role->shortname == $moodlerole) {
                    $DB->insert_record('local_campusconnect_rolemap',
                                       (object)array(
                                           'ccrolename' => $ccrole,
                                           'moodleroleid' => $role->id
                                       ));
                }
            }
        }

        member_personid::reset_default_mapping(); // Clear the static variable.

        // Statslib_test now has an annoying habit of creating an unwanted course as well - delete the record if it exists.
        $unwantedcourses = $DB->get_records_select('course', 'id <> ?', array(SITEID), '', 'id');
        foreach ($unwantedcourses as $unwantedcourse) {
            $DB->delete_records('course', array('id' => $unwantedcourse->id));
        }
    }

    // Helper functions.

    protected function create_text_profile_field($fieldname) {
        global $CFG;
        require_once($CFG->dirroot.'/user/profile/definelib.php');
        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/profile/field/text/define.class.php');
        $data = (object)array(
            'categoryid' => 1,
            'datatype' => 'text',
            'shortname' => $fieldname,
            'name' => $fieldname,
            'required' => 0,
            'locked' => 0,
            'forceunique' => 0,
            'signup' => 0,
            'visible' => PROFILE_VISIBLE_ALL,
            'param1' => 30,
            'param2' => 2048,
            'param3' => 0,
        );
        $formfield = new profile_define_text();
        $formfield->define_save($data);
    }

    protected function set_profile_field($user, $field, $value) {
        global $DB;
        $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => $field));
        if ($existing = $DB->get_record('user_info_data', array('fieldid' => $fieldid, 'userid' => $user->id))) {
            $upd = (object)array(
                'id' => $existing->id,
                'data' => $value,
            );
            $DB->update_record('user_info_data', $upd);
        } else {
            $ins = (object)array(
                'fieldid' => $fieldid,
                'userid' => $user->id,
                'data' => $value,
            );
            $DB->insert_record('user_info_data', $ins);
        }
    }

    protected static function get_course_enrolments($courseid, $userids) {
        global $DB;

        list($usql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sql = "
        SELECT ue.id, ue.userid, e.enrol
          FROM {user_enrolments} ue
          JOIN {enrol} e ON e.id = ue.enrolid
         WHERE e.courseid = :courseid AND ue.userid $usql";
        $params['courseid'] = $courseid;

        return $DB->get_records_sql($sql, $params);
    }

    protected static function get_groups($courseid, $userid) {
        global $DB;

        $sql = "
        SELECT g.id, g.name
          FROM {groups} g
          JOIN {groups_members} gm ON gm.groupid = g.id
         WHERE g.courseid = :courseid AND gm.userid = :userid
         ORDER BY g.name ASC
        ";
        $params = array('courseid' => $courseid, 'userid' => $userid);

        return $DB->get_records_sql_menu($sql, $params);
    }

    // Actual tests start here.

    public function test_parse_memberdata() {
        global $DB;

        // Gain access to the protected function 'extract_parallel_groups'.
        $class = new ReflectionClass('\local_campusconnect\membership');
        $extract = $class->getMethod('extract_parallel_groups');
        $extract->setAccessible(true);

        $resourceid = -10;
        $memberdata = json_decode($this->coursemembers);
        $this->assertNotEmpty($memberdata);

        $this->assertEmpty($DB->get_records('local_campusconnect_mbr'));
        membership::create($resourceid, $this->settings[2], $memberdata, $this->transferdetails);

        $members = $DB->get_records('local_campusconnect_mbr', array(), 'id');

        $this->assertCount(4, $members);
        $member1 = array_shift($members);
        $member2 = array_shift($members);
        $member3 = array_shift($members);
        $member4 = array_shift($members);

        $this->assertEquals('abc_1234', $member1->cmscourseid);
        $this->assertEquals('user1', $member1->personid);
        $this->assertEquals(membership::ROLE_ASSISTANT, $member1->role);
        $this->assertEquals(membership::STATUS_CREATED, $member1->status);
        $this->assertEquals(array(0 => membership::ROLE_LECTURER), $extract->invoke(null, $member1));

        $this->assertEquals('abc_1234', $member2->cmscourseid);
        $this->assertEquals('user2', $member2->personid);
        $this->assertEquals(membership::ROLE_STUDENT, $member2->role);
        $this->assertEquals(membership::STATUS_CREATED, $member2->status);
        $this->assertEquals(array(), $extract->invoke(null, $member2));

        $this->assertEquals('abc_1234', $member3->cmscourseid);
        $this->assertEquals('user3', $member3->personid);
        $this->assertEquals(membership::ROLE_LECTURER, $member3->role);
        $this->assertEquals(membership::STATUS_CREATED, $member3->status);
        $this->assertEquals(array(
                                0 => membership::ROLE_STUDENT,
                                1 => membership::ROLE_ASSISTANT,
                                2 => membership::ROLE_STUDENT
                            ), $extract->invoke(null, $member3));

        $this->assertEquals('abc_1234', $member4->cmscourseid);
        $this->assertEquals('user4', $member4->personid);
        $this->assertEquals(membership::ROLE_UNSPECIFIED, $member4->role);
        $this->assertEquals(membership::STATUS_CREATED, $member4->status);
        $this->assertEquals(array(1 => membership::ROLE_UNSPECIFIED), $extract->invoke(null, $member4));
    }

    public function test_create_members_nogroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        unset($course->groupScenario);
        unset($course->groups);
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        $members = json_decode($this->coursemembers);
        for ($i = 1; $i <= 2; $i++) {
            if ($i == 1) {
                // Create the course members.
                membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            } else {
                // Update the course members - check that an identical update does not break anything.
                membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            }

            // Check the users are enroled on the 'real' course.
            $userids = array();
            foreach ($this->users as $user) {
                $userids[] = $user->id;
            }

            $userenrolments = self::get_course_enrolments($course1->id, $userids);
            $this->assertCount(4, $userenrolments);
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
            }

            // Check no users have been enroled on the course link.
            $userenrolments = self::get_course_enrolments($course2->id, $userids);
            $this->assertEmpty($userenrolments);

            // Check the roles that each user has been given.
            $context = context_course::instance($course1->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);

            $this->assertCount(1, $roles1); // Each user should only have 1 role in the course.
            $this->assertCount(1, $roles2);
            $this->assertCount(1, $roles3);
            $this->assertCount(1, $roles4);

            $role1 = reset($roles1);
            $role2 = reset($roles2);
            $role3 = reset($roles3);
            $role4 = reset($roles4);

            $this->assertEquals('teacher', $role1->shortname); // Membership role..
            $this->assertEquals('student', $role2->shortname); // Membership role.
            $this->assertEquals('editingteacher', $role3->shortname); // Membership role.
            $this->assertEquals('student', $role4->shortname); // Default role.

            // Check no group memberships.
            $groups1 = self::get_groups($course1->id, $this->users[0]->id);
            $groups2 = self::get_groups($course1->id, $this->users[1]->id);
            $groups3 = self::get_groups($course1->id, $this->users[2]->id);
            $groups4 = self::get_groups($course1->id, $this->users[3]->id);

            $this->assertEmpty($groups1);
            $this->assertEmpty($groups2);
            $this->assertEmpty($groups3);
            $this->assertEmpty($groups4);
        }
    }

    public function test_create_members_separategroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        $members = json_decode($this->coursemembers);
        for ($i = 1; $i <= 2; $i++) {
            if ($i == 1) {
                // Create the course members.
                membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            } else {
                // Update the course members - check that an identical update does not break anything.
                membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            }

            // Check the users are enroled on the 'real' course.
            $userids = array();
            foreach ($this->users as $user) {
                $userids[] = $user->id;
            }

            $userenrolments = self::get_course_enrolments($course1->id, $userids);
            $this->assertCount(4, $userenrolments);
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
            }

            // Check no users have been enroled on the course link.
            $userenrolments = self::get_course_enrolments($course2->id, $userids);
            $this->assertEmpty($userenrolments);

            // Check the roles that each user has been given.
            $context = context_course::instance($course1->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);

            $this->assertCount(1, $roles1);
            $this->assertCount(1, $roles2);
            $this->assertCount(2, $roles3);
            $this->assertCount(1, $roles4);

            $role1 = reset($roles1);
            $role2 = reset($roles2);
            $role3 = reset($roles3);
            $role3b = next($roles3);
            $role4 = reset($roles4);

            $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
            $this->assertEquals('student', $role2->shortname); // Membership role.
            $this->assertEquals('teacher', $role3->shortname); // From group 1.
            $this->assertEquals('student', $role3b->shortname); // From group 0.
            $this->assertEquals('student', $role4->shortname); // Default role.

            // Check the group memberships.
            $groups1 = self::get_groups($course1->id, $this->users[0]->id);
            $groups2 = self::get_groups($course1->id, $this->users[1]->id);
            $groups3 = self::get_groups($course1->id, $this->users[2]->id);
            $groups4 = self::get_groups($course1->id, $this->users[3]->id);

            $this->assertCount(1, $groups1);
            $this->assertCount(0, $groups2);
            $this->assertCount(2, $groups3);
            $this->assertCount(1, $groups4);

            $this->assertEmpty(array_diff(array('Test Group1'), $groups1));
            $this->assertEmpty(array_diff(array('Test Group1', 'Test Group2'), $groups3));
            $this->assertEmpty(array_diff(array('Test Group2'), $groups4));
        }
    }

    public function test_create_members_separatecourses() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(4, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);

        $members = json_decode($this->coursemembers);
        for ($i = 1; $i <= 2; $i++) {
            if ($i == 1) {
                // Create the course members.
                membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            } else {
                // Update the course members - check that an identical update does not break anything.
                membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            }

            // Check the users are enroled on the 'real' courses.
            $userids = array();
            foreach ($this->users as $user) {
                $userids[] = $user->id;
            }

            $userenrolments = self::get_course_enrolments($course1->id, $userids);
            $this->assertCount(3, $userenrolments); // User 1, 2, 3.
            $enroleduserids = array();
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
                $enroleduserids[] = $userenrolment->userid;
            }
            $this->assertEmpty(array_diff(array($this->users[0]->id, $this->users[1]->id, $this->users[2]->id), $enroleduserids));

            $userenrolments = self::get_course_enrolments($course3->id, $userids);
            $this->assertCount(2, $userenrolments); // User 3, 4.
            $enroleduserids = array();
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
                $enroleduserids[] = $userenrolment->userid;
            }
            $this->assertEmpty(array_diff(array($this->users[2]->id, $this->users[3]->id), $enroleduserids));

            // Check no users have been enroled on the course links.
            $userenrolments = self::get_course_enrolments($course2->id, $userids);
            $this->assertEmpty($userenrolments);
            $userenrolments = self::get_course_enrolments($course4->id, $userids);
            $this->assertEmpty($userenrolments);

            // Check the roles that each user has been given in course1.
            $context = context_course::instance($course1->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);

            $this->assertCount(1, $roles1); // Each user should only have 1 role in the course.
            $this->assertCount(1, $roles2);
            $this->assertCount(1, $roles3);
            $this->assertCount(0, $roles4);

            $role1 = reset($roles1);
            $role2 = reset($roles2);
            $role3 = reset($roles3);

            $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
            $this->assertEquals('student', $role2->shortname); // Membership role.
            $this->assertEquals('student', $role3->shortname); // From group 1.

            // Check the roles that each user has been given in course3.
            $context = context_course::instance($course3->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);

            $this->assertCount(0, $roles1); // Each user should only have 1 role in the course.
            $this->assertCount(0, $roles2);
            $this->assertCount(1, $roles3);
            $this->assertCount(1, $roles4);

            $role3 = reset($roles3);
            $role4 = reset($roles4);

            $this->assertEquals('teacher', $role3->shortname); // From group 1.
            $this->assertEquals('student', $role4->shortname); // Default role.

            // Check the group memberships for course 1.
            $groups1 = self::get_groups($course1->id, $this->users[0]->id);
            $groups2 = self::get_groups($course1->id, $this->users[1]->id);
            $groups3 = self::get_groups($course1->id, $this->users[2]->id);
            $groups4 = self::get_groups($course1->id, $this->users[3]->id);

            $this->assertCount(0, $groups1);
            $this->assertCount(0, $groups2);
            $this->assertCount(0, $groups3);
            $this->assertCount(0, $groups4);

            // Check the group memberships for course 3.
            $groups1 = self::get_groups($course3->id, $this->users[0]->id);
            $groups2 = self::get_groups($course3->id, $this->users[1]->id);
            $groups3 = self::get_groups($course3->id, $this->users[2]->id);
            $groups4 = self::get_groups($course3->id, $this->users[3]->id);

            $this->assertCount(0, $groups1);
            $this->assertCount(0, $groups2);
            $this->assertCount(0, $groups3);
            $this->assertCount(0, $groups4);
        }
    }

    public function test_create_members_separatelecturers() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_LECTURERS;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses); // Group0 + group1 (Humphrey Bogart).
        $course2 = array_shift($courses); // Course link.

        $members = json_decode($this->coursemembers);
        for ($i = 1; $i <= 2; $i++) {
            if ($i == 1) {
                // Create the course members.
                membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            } else {
                // Update the course members - check that an identical update does not break anything.
                membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            }

            // Check the users are enroled on the 'real' course.
            $userids = array();
            foreach ($this->users as $user) {
                $userids[] = $user->id;
            }

            $userenrolments = self::get_course_enrolments($course1->id, $userids);
            $this->assertCount(4, $userenrolments); // User 1, 2, 3, 4 - group0 + group1.
            $enroleduserids = array();
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
                $enroleduserids[] = $userenrolment->userid;
            }
            $this->assertEmpty(array_diff(array($this->users[0]->id, $this->users[1]->id, $this->users[2]->id, $this->users[3]->id),
                                          $enroleduserids));

            // Check no users have been enroled on the course links.
            $userenrolments = self::get_course_enrolments($course2->id, $userids);
            $this->assertEmpty($userenrolments);

            // Check the roles that each user has been given in the course.
            $context = context_course::instance($course1->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);

            $this->assertCount(1, $roles1);
            $this->assertCount(1, $roles2);
            $this->assertCount(2, $roles3);
            $this->assertCount(1, $roles4);

            $role1 = reset($roles1);
            $role2 = reset($roles2);
            $role3 = reset($roles3);
            $role3b = next($roles3);
            $role4 = reset($roles4);

            $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
            $this->assertEquals('student', $role2->shortname); // Membership role.
            $this->assertEquals('teacher', $role3->shortname); // From group 1.
            $this->assertEquals('student', $role3b->shortname); // From group 0.
            $this->assertEquals('student', $role4->shortname); // Default role.

            // Check the group memberships for course 1.
            $groups1 = self::get_groups($course1->id, $this->users[0]->id);
            $groups2 = self::get_groups($course1->id, $this->users[1]->id);
            $groups3 = self::get_groups($course1->id, $this->users[2]->id);
            $groups4 = self::get_groups($course1->id, $this->users[3]->id);

            $this->assertCount(1, $groups1);
            $this->assertCount(0, $groups2);
            $this->assertCount(2, $groups3);
            $this->assertCount(1, $groups4);

            $this->assertEmpty(array_diff(array('Test Group1'), $groups1));
            $this->assertEmpty(array_diff(array('Test Group1', 'Test Group2'), $groups3));
            $this->assertEmpty(array_diff(array('Test Group2'), $groups4));
        }
    }

    public function test_update_members_separategroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Create the course members (this is already tested above, so no further checking done here).
        $members = json_decode($this->coursemembers);
        membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
        membership::assign_all_roles($this->settings[2]);

        // Update the course members to new values.
        foreach ($members->members as $idx => $member) {
            if ($member->personID == 'user1') {
                unset($members->members[$idx]); // Remove 'user1' from the course.
            } else if ($member->personID == 'user2') {
                $members->members[$idx]->role = 0; // Convert 'user2' to a lecturer (not student).
            }
        }
        $members->members[] = (object)array(
            'personID' => 'user5', // Add 'user5' to the course (student, in 'Test Group2').
            'role' => 1,
            'groups' => array(
                (object)array('num' => 1),
            )
        );
        membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
        membership::assign_all_roles($this->settings[2]);

        // Check the users are enroled on the 'real' course.
        $userids = array();
        foreach ($this->users as $user) {
            $userids[] = $user->id;
        }

        $userenrolments = self::get_course_enrolments($course1->id, $userids);
        $this->assertCount(4, $userenrolments); // Should still be 4 enrolments (user5 has replaced user1).
        foreach ($userenrolments as $userenrolment) {
            $this->assertEquals('campusconnect', $userenrolment->enrol);
        }

        // Check no users have been enroled on the course link.
        $userenrolments = self::get_course_enrolments($course2->id, $userids);
        $this->assertEmpty($userenrolments);

        // Check the roles that each user has been given.
        $context = context_course::instance($course1->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);
        $roles5 = get_user_roles($context, $this->users[4]->id, false);

        $this->assertCount(0, $roles1); // No role for 'user1' any more.
        $this->assertCount(1, $roles2);
        $this->assertCount(2, $roles3);
        $this->assertCount(1, $roles4);
        $this->assertCount(1, $roles5); // Role added for 'user5'.

        $role2 = reset($roles2);
        $role3 = reset($roles3);
        $role3b = next($roles3);
        $role4 = reset($roles4);
        $role5 = reset($roles5);

        $this->assertEquals('editingteacher', $role2->shortname); // Membership role (changed from student).
        $this->assertEquals('teacher', $role3->shortname); // From group 1.
        $this->assertEquals('student', $role3b->shortname); // From group 0.
        $this->assertEquals('student', $role4->shortname); // Default role.
        $this->assertEquals('student', $role5->shortname); // Membership role (new).

        // Check the group memberships.
        $groups1 = self::get_groups($course1->id, $this->users[0]->id);
        $groups2 = self::get_groups($course1->id, $this->users[1]->id);
        $groups3 = self::get_groups($course1->id, $this->users[2]->id);
        $groups4 = self::get_groups($course1->id, $this->users[3]->id);
        $groups5 = self::get_groups($course1->id, $this->users[4]->id);

        $this->assertCount(0, $groups1); // No groups for 'user1' any more.
        $this->assertCount(0, $groups2);
        $this->assertCount(2, $groups3);
        $this->assertCount(1, $groups4);
        $this->assertCount(1, $groups5); // New groups for 'user5'.

        $this->assertEmpty(array_diff(array('Test Group1', 'Test Group2'), $groups3));
        $this->assertEmpty(array_diff(array('Test Group2'), $groups4));
        $this->assertEmpty(array_diff(array('Test Group2'), $groups5));
    }

    public function test_delete_members_separategroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Create the course members (this is already tested above, so no further checking done here).
        $members = json_decode($this->coursemembers);
        membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
        membership::assign_all_roles($this->settings[2]);

        // Delete the course members resource.
        membership::delete($memberresourceid, $this->settings[2]);
        membership::assign_all_roles($this->settings[2]);

        // Check the users are enroled on the 'real' course.
        $userids = array();
        foreach ($this->users as $user) {
            $userids[] = $user->id;
        }

        $userenrolments = self::get_course_enrolments($course1->id, $userids);
        $this->assertEmpty($userenrolments); // No remaining enrolments expected.

        // Check the users now have no roles.
        $context = context_course::instance($course1->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);

        $this->assertCount(0, $roles1);
        $this->assertCount(0, $roles2);
        $this->assertCount(0, $roles3);
        $this->assertCount(0, $roles4);

        // Check the users are no longer in any groups.
        $groups1 = self::get_groups($course1->id, $this->users[0]->id);
        $groups2 = self::get_groups($course1->id, $this->users[1]->id);
        $groups3 = self::get_groups($course1->id, $this->users[2]->id);
        $groups4 = self::get_groups($course1->id, $this->users[3]->id);

        $this->assertCount(0, $groups1);
        $this->assertCount(0, $groups2);
        $this->assertCount(0, $groups3);
        $this->assertCount(0, $groups4);
    }

    // Test that processing a 'course' resource after processing the 'course_members' resource for that same course
    // still results in the users being enroled in the course.
    public function test_course_create_event_separatecourses() {
        global $DB;

        $courseresourceid = -10;
        $memberresourceid = -20;

        // Create the course members (note - BEFORE the course creation has been processed).
        $members = json_decode($this->coursemembers);
        membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
        membership::assign_all_roles($this->settings[2]);

        // Course create request from participant 1 to participant 2 (AFTER the course_members have been processed).
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(4, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);

        // Check the users are enroled on the 'real' courses.
        $userids = array();
        foreach ($this->users as $user) {
            $userids[] = $user->id;
        }

        $userenrolments = self::get_course_enrolments($course1->id, $userids);
        $this->assertCount(3, $userenrolments); // User 1, 2, 3.
        $enroleduserids = array();
        foreach ($userenrolments as $userenrolment) {
            $this->assertEquals('campusconnect', $userenrolment->enrol);
            $enroleduserids[] = $userenrolment->userid;
        }
        $this->assertEmpty(array_diff(array($this->users[0]->id, $this->users[1]->id, $this->users[2]->id), $enroleduserids));

        $userenrolments = self::get_course_enrolments($course3->id, $userids);
        $this->assertCount(2, $userenrolments); // User 3, 4.
        $enroleduserids = array();
        foreach ($userenrolments as $userenrolment) {
            $this->assertEquals('campusconnect', $userenrolment->enrol);
            $enroleduserids[] = $userenrolment->userid;
        }
        $this->assertEmpty(array_diff(array($this->users[2]->id, $this->users[3]->id), $enroleduserids));

        // Check no users have been enroled on the course links.
        $userenrolments = self::get_course_enrolments($course2->id, $userids);
        $this->assertEmpty($userenrolments);
        $userenrolments = self::get_course_enrolments($course4->id, $userids);
        $this->assertEmpty($userenrolments);

        // Check the roles that each user has been given in course1.
        $context = context_course::instance($course1->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);

        $this->assertCount(1, $roles1); // Each user should only have 1 role in the course.
        $this->assertCount(1, $roles2);
        $this->assertCount(1, $roles3);
        $this->assertCount(0, $roles4);

        $role1 = reset($roles1);
        $role2 = reset($roles2);
        $role3 = reset($roles3);

        $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
        $this->assertEquals('student', $role2->shortname); // Membership role.
        $this->assertEquals('student', $role3->shortname); // From group 1.

        // Check the roles that each user has been given in course3.
        $context = context_course::instance($course3->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);

        $this->assertCount(0, $roles1); // Each user should only have 1 role in the course.
        $this->assertCount(0, $roles2);
        $this->assertCount(1, $roles3);
        $this->assertCount(1, $roles4);

        $role3 = reset($roles3);
        $role4 = reset($roles4);

        $this->assertEquals('teacher', $role3->shortname); // From group 1.
        $this->assertEquals('student', $role4->shortname); // Default role.

        // Check the group memberships for course 1.
        $groups1 = self::get_groups($course1->id, $this->users[0]->id);
        $groups2 = self::get_groups($course1->id, $this->users[1]->id);
        $groups3 = self::get_groups($course1->id, $this->users[2]->id);
        $groups4 = self::get_groups($course1->id, $this->users[3]->id);

        $this->assertCount(0, $groups1);
        $this->assertCount(0, $groups2);
        $this->assertCount(0, $groups3);
        $this->assertCount(0, $groups4);

        // Check the group memberships for course 3.
        $groups1 = self::get_groups($course3->id, $this->users[0]->id);
        $groups2 = self::get_groups($course3->id, $this->users[1]->id);
        $groups3 = self::get_groups($course3->id, $this->users[2]->id);
        $groups4 = self::get_groups($course3->id, $this->users[3]->id);

        $this->assertCount(0, $groups1);
        $this->assertCount(0, $groups2);
        $this->assertCount(0, $groups3);
        $this->assertCount(0, $groups4);
    }

    // Test that creating a new user account, after the 'course_members' resource has already been processed,
    // still results in that user being correctly enroled into the relevant course.
    public function test_user_create_event_separatecourses() {
        global $DB, $CFG;

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(4, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);

        // Create the course members.
        $members = json_decode($this->coursemembers);
        $members->members[] = (object)array(
            'personID' => 'user6@example.com', // Add an extra 'user6' (who does not yet exist on the system).
            'personIDtype' => 'ecs_email',
            'role' => 2, // Assistant (Moodle: 'teacher').
            'groups' => array(
                (object)array('num' => 1), // Enrol into 'Test Group2'.
            )
        );
        membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
        membership::assign_all_roles($this->settings[2]);

        // Now create 'user6'.
        $user6 = $this->getDataGenerator()->create_user(array('username' => 'user6', 'email' => 'user6@example.com'));

        // Manually trigger the 'user_created' event (as the data generator does not do this).
        \core\event\user_created::create_from_userid($user6->id)->trigger();

        // Check the users are enroled on the 'real' courses.
        $userids = array();
        foreach ($this->users as $user) {
            $userids[] = $user->id;
        }
        $userids[] = $user6->id;

        $userenrolments = self::get_course_enrolments($course1->id, $userids); // Test Group1.
        $this->assertCount(3, $userenrolments); // User 1, 2, 3.
        $enroleduserids = array();
        foreach ($userenrolments as $userenrolment) {
            $this->assertEquals('campusconnect', $userenrolment->enrol);
            $enroleduserids[] = $userenrolment->userid;
        }
        $this->assertEmpty(array_diff(array($this->users[0]->id, $this->users[1]->id, $this->users[2]->id), $enroleduserids));

        $userenrolments = self::get_course_enrolments($course3->id, $userids); // Test Group2.
        $this->assertCount(3, $userenrolments); // User 3, 4, 6.
        $enroleduserids = array();
        foreach ($userenrolments as $userenrolment) {
            $this->assertEquals('campusconnect', $userenrolment->enrol);
            $enroleduserids[] = $userenrolment->userid;
        }
        $this->assertEmpty(array_diff(array($this->users[2]->id, $this->users[3]->id, $user6->id), $enroleduserids));

        // Check no users have been enroled on the course links.
        $userenrolments = self::get_course_enrolments($course2->id, $userids);
        $this->assertEmpty($userenrolments);
        $userenrolments = self::get_course_enrolments($course4->id, $userids);
        $this->assertEmpty($userenrolments);

        // Check the roles that each user has been given in course1.
        $context = context_course::instance($course1->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);
        $roles6 = get_user_roles($context, $user6->id, false);

        $this->assertCount(1, $roles1); // Each user should only have 1 role in the course.
        $this->assertCount(1, $roles2);
        $this->assertCount(1, $roles3);
        $this->assertCount(0, $roles4);
        $this->assertCount(0, $roles6); // Not enroled in 'Test Group1'.

        $role1 = reset($roles1);
        $role2 = reset($roles2);
        $role3 = reset($roles3);

        $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
        $this->assertEquals('student', $role2->shortname); // Membership role.
        $this->assertEquals('student', $role3->shortname); // From group 1.

        // Check the roles that each user has been given in course3.
        $context = context_course::instance($course3->id);
        $roles1 = get_user_roles($context, $this->users[0]->id, false);
        $roles2 = get_user_roles($context, $this->users[1]->id, false);
        $roles3 = get_user_roles($context, $this->users[2]->id, false);
        $roles4 = get_user_roles($context, $this->users[3]->id, false);
        $roles6 = get_user_roles($context, $user6->id, false);

        $this->assertCount(0, $roles1); // Each user should only have 1 role in the course.
        $this->assertCount(0, $roles2);
        $this->assertCount(1, $roles3);
        $this->assertCount(1, $roles4);
        $this->assertCount(1, $roles6);

        $role3 = reset($roles3);
        $role4 = reset($roles4);
        $role6 = reset($roles6);

        $this->assertEquals('teacher', $role3->shortname); // From group 1.
        $this->assertEquals('student', $role4->shortname); // Default role.
        $this->assertEquals('teacher', $role6->shortname); // Membership role.

        // Check the group memberships for course 1.
        $groups1 = self::get_groups($course1->id, $this->users[0]->id);
        $groups2 = self::get_groups($course1->id, $this->users[1]->id);
        $groups3 = self::get_groups($course1->id, $this->users[2]->id);
        $groups4 = self::get_groups($course1->id, $this->users[3]->id);
        $groups6 = self::get_groups($course1->id, $user6->id);

        $this->assertCount(0, $groups1);
        $this->assertCount(0, $groups2);
        $this->assertCount(0, $groups3);
        $this->assertCount(0, $groups4);
        $this->assertCount(0, $groups6);

        // Check the group memberships for course 3.
        $groups1 = self::get_groups($course3->id, $this->users[0]->id);
        $groups2 = self::get_groups($course3->id, $this->users[1]->id);
        $groups3 = self::get_groups($course3->id, $this->users[2]->id);
        $groups4 = self::get_groups($course3->id, $this->users[3]->id);
        $groups6 = self::get_groups($course3->id, $user6->id);

        $this->assertCount(0, $groups1);
        $this->assertCount(0, $groups2);
        $this->assertCount(0, $groups3);
        $this->assertCount(0, $groups4);
        $this->assertCount(0, $groups6);
    }

    public function test_update_mappings() {
        // Define a custom user profile field.
        $this->create_text_profile_field('eppn');
        participantsettings::reset_custom_fields();

        // Set the new mapping.
        $newmapping = array(
            courselink::PERSON_UNIQUECODE => 'flowerpot', // Non-existent user field.
            courselink::PERSON_EPPN => 'custom_eppn', // Map onto user custom field.
            courselink::PERSON_LOGIN => 'username',
            //\local_campusconnect\courselink::PERSON_LOGINUID, // Mapping not specified => expect to be mapped to 'null'.
            courselink::PERSON_UID => null,  // Remove default mapping onto 'id'.
            courselink::PERSON_EMAIL => 'department', // Change from 'email' to 'department'.
            'doesnotexist' => 'firstname', // Invalid ECS field - should be ignored.
        );
        member_personid::set_mapping($newmapping);
        $mapping = member_personid::get_mapping();

        $expectedmapping = array(
            courselink::PERSON_UNIQUECODE => null,
            courselink::PERSON_EPPN => 'custom_eppn',
            courselink::PERSON_LOGIN => 'username',
            courselink::PERSON_LOGINUID => null,
            courselink::PERSON_UID => null,
            courselink::PERSON_EMAIL => 'department',
        );
        $this->assertEquals($expectedmapping, $mapping);

        $userfield = member_personid::get_userfield_from_type(courselink::PERSON_UNIQUECODE);
        $this->assertNull($userfield);

        $userfield = member_personid::get_userfield_from_type(courselink::PERSON_LOGIN);
        $this->assertEquals('username', $userfield);

        $userfield = member_personid::get_userfield_from_type(courselink::PERSON_EMAIL);
        $this->assertEquals('department', $userfield);
    }

    public function test_personid_mapping() {
        global $DB;

        // Define a custom user profile field.
        $this->create_text_profile_field('eppn');
        participantsettings::reset_custom_fields();

        // Set up the personidtype mappings.
        $newmapping = array(
            courselink::PERSON_EPPN => 'custom_eppn', // Mapped on to custom field.
            courselink::PERSON_LOGIN => 'username', // Default mapping if no type specified.
            courselink::PERSON_EMAIL => 'email',
            // All other types will be unmapped.
        );
        member_personid::set_mapping($newmapping);

        // Course create request from participant 1 to participant 2.
        $courseresourceid = -10;
        $memberresourceid = -20;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;
        course::create($courseresourceid, $this->settings[2], $course, $this->transferdetails);

        // Get the details of the two courses created.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        $members = json_decode($this->coursemembers);

        // Adjust the members data to match via a range of different fields.
        // User1 should default to matching via 'ecs_login' => 'username'.
        $members->members[0]->personID = 'user1';
        // User2 - match by 'ecs_email' => 'email'.
        $members->members[1]->personID = 'user2@example.com';
        $members->members[1]->personIDtype = courselink::PERSON_EMAIL;
        // User3 - match by 'ecs_eppn' => 'custom_eppn'.
        $members->members[2]->personID = 'user3eppn';
        $members->members[2]->personIDtype = courselink::PERSON_EPPN;
        $this->set_profile_field($this->users[2], 'eppn', 'user3eppn');
        // User4 - match explicitly by 'ecs_login' => 'username'.
        $members->members[3]->personID = 'user4';
        $members->members[3]->personIDtype = courselink::PERSON_LOGIN;
        // User5 - match by 'ecs_loginuid' - not mapped, so no user should be found.
        $members->members[4] = (object)array(
            'personID' => 'user5',
            'personIDtype' => courselink::PERSON_LOGINUID,
            'groups' => array(
                (object)array('num' => 1),
            ),
        ); // Not in the sample data defined at the top of this file, so the whole object needs inserting.
        // User6 - match by 'ecs_login', but with a username that will not be found.
        $user6 = $this->getDataGenerator()->create_user(array('username' => 'user6', 'email' => 'user6@example.com'));
        $members->members[5] = (object)array(
            'personID' => 'user6doesnotexist',
            'personIDtype' => courselink::PERSON_LOGIN,
            'groups' => array(
                (object)array('num' => 1),
            ),
        ); // Not in the sample data defined at the top of this file, so the whole object needs inserting.

        // Check the personid mappings.
        $personids = array();
        foreach ($members->members as $member) {
            $type = member_personid::get_type_from_member($member);
            $personids[] = new member_personid($member->personID, $type);
        }
        // Expect: [
        //  courselink::PERSON_EMAIL => [ 'user2@example.com' => user2->id ]
        //  courselink::PERSON_EPPN => [ 'user3eppn' => user3->id ]
        //  courselink::PERSON_LOGIN => [ 'user1' => user1->id, 'user4' => user4->id ]
        // ]
        // user5 / user6 should not be mapped at all.
        $useridmap = membership::get_userids_from_personids($personids);
        $this->assertCount(3, $useridmap);

        $this->assertArrayHasKey(courselink::PERSON_EMAIL, $useridmap);
        $userids = $useridmap[courselink::PERSON_EMAIL];
        $this->assertCount(1, $userids);
        $this->assertArrayHasKey('user2@example.com', $userids);
        $this->assertEquals($this->users[1]->id, $userids['user2@example.com']);

        $this->assertArrayHasKey(courselink::PERSON_EPPN, $useridmap);
        $userids = $useridmap[courselink::PERSON_EPPN];
        $this->assertCount(1, $userids);
        $this->assertArrayHasKey('user3eppn', $userids);
        $this->assertEquals($this->users[2]->id, $userids['user3eppn']);

        $this->assertArrayHasKey(courselink::PERSON_LOGIN, $useridmap);
        $userids = $useridmap[courselink::PERSON_LOGIN];
        $this->assertCount(2, $userids);
        $this->assertArrayHasKey('user1', $userids);
        $this->assertEquals($this->users[0]->id, $userids['user1']);
        $this->assertArrayHasKey('user4', $userids);
        $this->assertEquals($this->users[3]->id, $userids['user4']);

        for ($i = 1; $i <= 2; $i++) {
            if ($i == 1) {
                // Create the course members.
                membership::create($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            } else {
                // Update the course members - check that an identical update does not break anything.
                membership::update($memberresourceid, $this->settings[2], $members, $this->transferdetails);
                membership::assign_all_roles($this->settings[2]);
            }

            // Check the users are enroled on the 'real' course.
            $useridmap = array();
            foreach ($this->users as $user) {
                $useridmap[] = $user->id;
            }
            $useridmap[] = $user6->id;

            $userenrolments = self::get_course_enrolments($course1->id, $useridmap);
            $this->assertCount(4, $userenrolments); // User5 + user6 should not be found by mapping, so no enrolments expected.
            foreach ($userenrolments as $userenrolment) {
                $this->assertEquals('campusconnect', $userenrolment->enrol);
            }

            // Check no users have been enroled on the course link.
            $userenrolments = self::get_course_enrolments($course2->id, $useridmap);
            $this->assertEmpty($userenrolments);

            // Check the roles that each user has been given.
            $context = context_course::instance($course1->id);
            $roles1 = get_user_roles($context, $this->users[0]->id, false);
            $roles2 = get_user_roles($context, $this->users[1]->id, false);
            $roles3 = get_user_roles($context, $this->users[2]->id, false);
            $roles4 = get_user_roles($context, $this->users[3]->id, false);
            $roles5 = get_user_roles($context, $this->users[4]->id, false);
            $roles6 = get_user_roles($context, $user6->id, false);

            $this->assertCount(1, $roles1);
            $this->assertCount(1, $roles2);
            $this->assertCount(2, $roles3);
            $this->assertCount(1, $roles4);
            $this->assertCount(0, $roles5); // User5 + user6 should not be found by mapping, so no roles expected.
            $this->assertCount(0, $roles6);

            $role1 = reset($roles1);
            $role2 = reset($roles2);
            $role3 = reset($roles3);
            $role3b = next($roles3);
            $role4 = reset($roles4);

            $this->assertEquals('editingteacher', $role1->shortname); // From group 0.
            $this->assertEquals('student', $role2->shortname); // Membership role.
            $this->assertEquals('teacher', $role3->shortname); // From group 1.
            $this->assertEquals('student', $role3b->shortname); // From group 0.
            $this->assertEquals('student', $role4->shortname); // Default role.

            // Check the group memberships.
            $groups1 = self::get_groups($course1->id, $this->users[0]->id);
            $groups2 = self::get_groups($course1->id, $this->users[1]->id);
            $groups3 = self::get_groups($course1->id, $this->users[2]->id);
            $groups4 = self::get_groups($course1->id, $this->users[3]->id);
            $groups5 = self::get_groups($course1->id, $this->users[4]->id);
            $groups6 = self::get_groups($course1->id, $user6->id);

            $this->assertCount(1, $groups1);
            $this->assertCount(0, $groups2);
            $this->assertCount(2, $groups3);
            $this->assertCount(1, $groups4);
            $this->assertCount(0, $groups5); // User5 + user6 should not be found by mapping, so no groups expected.
            $this->assertCount(0, $groups6);

            $this->assertEmpty(array_diff(array('Test Group1'), $groups1));
            $this->assertEmpty(array_diff(array('Test Group1', 'Test Group2'), $groups3));
            $this->assertEmpty(array_diff(array('Test Group2'), $groups4));
        }
    }
}