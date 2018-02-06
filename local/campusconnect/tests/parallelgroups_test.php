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
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\course;
use local_campusconnect\details;
use local_campusconnect\directory;
use local_campusconnect\directorytree;
use local_campusconnect\ecssettings;
use local_campusconnect\event;
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
 * Class local_campusconnect_parallelgroups_test
 * @group local_campusconnect
 */
class local_campusconnect_parallelgroups_test extends advanced_testcase {
    /** @var ecssettings[] $settings */
    protected $settings = array();
    protected $mid = array();
    /** @var directory[] $directory */
    protected $directory = array();
    /** @var details $transferdetails */
    protected $transferdetails = null;

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
            },
            {
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

    protected $altgroupdata = '
        [
            {
                "title": "Renamed Group1",
                "comment": "Updated comment",
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
                "lecturers":
                [
                    {
                        "firstName": "Humphrey",
                        "lastName": "Bogart"
                    }
                ]
            },
            {
                "title": "Adding a title to group 3"
            },
            {
                "title": "Newly added group 4",
                "lecturers":
                [
                    {
                        "firstName": "Sam",
                        "lastName": "Spade"
                    }
                ]
            }
        ]
    ';

    public function setUp() {
        global $DB;

        if (defined('SKIP_CAMPUSCONNECT_PARALLELGROUPS_TESTS')) {
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

        // Moodle seems to want to create a dummy course - get rid of it.
        $DB->delete_records_select('course', 'id > 1');
    }

    public function test_parallelgroups_none() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_NONE;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 2 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        $this->assertEquals('abc_1234', $course1->shortname);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertContains('Synergy Learning', $course1->summary);

        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $this->assertContains('Synergy Learning', $course2->summary);

        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course1->id));
        $this->assertEmpty(groups_get_all_groups($course2->id));

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals(0, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals(0, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course1->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        // --------------------------------
        // Update the group definition.
        // --------------------------------
        $course->groups = json_decode($this->altgroupdata);
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should still be 2 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        $this->assertEquals('abc_1234', $course1->shortname);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertContains('Synergy Learning', $course1->summary);

        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $this->assertContains('Synergy Learning', $course2->summary);

        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course1->id));
        $this->assertEmpty(groups_get_all_groups($course2->id));

        // Check parallel groups records have been updated.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(4, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);
        $pgroup4 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Renamed Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals(0, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Group 1', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals(0, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Adding a title to group 3', $pgroup3->grouptitle);
        $this->assertEquals($course1->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        $this->assertEquals('3', $pgroup4->groupnum);
        $this->assertEquals('abc_1234', $pgroup4->cmscourseid);
        $this->assertEquals('Newly added group 4', $pgroup4->grouptitle);
        $this->assertEquals($course1->id, $pgroup4->courseid);
        $this->assertEquals(0, $pgroup4->groupid);
    }

    public function test_parallelgroups_separategroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 2 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        $this->assertEquals('abc_1234', $course1->shortname);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertContains('Synergy Learning', $course1->summary);

        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $this->assertContains('Synergy Learning', $course2->summary);

        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check correct Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $groups = groups_get_all_groups($course1->id);
        ksort($groups);
        $this->assertCount(3, $groups);
        $group1 = array_shift($groups);
        $group2 = array_shift($groups);
        $group3 = array_shift($groups);

        $this->assertEquals('Test Group1', $group1->name);
        $this->assertEquals('Test Group2', $group2->name);
        $this->assertEquals('Group 2', $group3->name);

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals($group1->id, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals($group2->id, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course1->id, $pgroup3->courseid);
        $this->assertEquals($group3->id, $pgroup3->groupid);

        // --------------------------------
        // Update the group definition.
        // --------------------------------
        $course->groups = json_decode($this->altgroupdata);
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should still be 2 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        $this->assertEquals('abc_1234', $course1->shortname);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertContains('Synergy Learning', $course1->summary);

        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $this->assertContains('Synergy Learning', $course2->summary);

        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check Moodle groups have been updated.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $groups = groups_get_all_groups($course1->id);
        ksort($groups);
        $this->assertCount(4, $groups);
        $group1 = array_shift($groups);
        $group2 = array_shift($groups);
        $group3 = array_shift($groups);
        $group4 = array_shift($groups);

        $this->assertEquals('Renamed Group1', $group1->name);
        $this->assertEquals('Group 1', $group2->name);
        $this->assertEquals('Adding a title to group 3', $group3->name);
        $this->assertEquals('Newly added group 4', $group4->name);

        // Check parallel groups records have been updated.
        $pgroups = $DB->get_records('local_campusconnect_pgroup', array(), 'id');
        $this->assertCount(4, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);
        $pgroup4 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Renamed Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals($group1->id, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Group 1', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals($group2->id, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Adding a title to group 3', $pgroup3->grouptitle);
        $this->assertEquals($course1->id, $pgroup3->courseid);
        $this->assertEquals($group3->id, $pgroup3->groupid);

        $this->assertEquals('3', $pgroup4->groupnum);
        $this->assertEquals('abc_1234', $pgroup4->cmscourseid);
        $this->assertEquals('Newly added group 4', $pgroup4->grouptitle);
        $this->assertEquals($course1->id, $pgroup4->courseid);
        $this->assertEquals($group4->id, $pgroup4->groupid);
    }

    public function test_parallelgroups_separatecourses() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 6 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(6, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);
        $course5 = array_shift($courses);
        $course6 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        // PGroup 1.
        $this->assertEquals('Test course creation (Test Group1)', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);

        $this->assertEquals('Test course creation (Test Group1)', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);

        // PGroup 2.
        $this->assertEquals('Test course creation (Test Group2)', $course3->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);

        $this->assertEquals('Test course creation (Test Group2)', $course4->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course4->category);

        // PGroup 3.
        $this->assertEquals('Test course creation (Group 2)', $course5->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course5->category);

        $this->assertEquals('Test course creation (Group 2)', $course6->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course6->category);

        // PGroup 1.
        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 2.
        $this->assertFalse(course::check_redirect($course3->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course3->id));
        $actualredirect = course::check_redirect($course4->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 3.
        $this->assertFalse(course::check_redirect($course5->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course5->id));
        $actualredirect = course::check_redirect($course6->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course1->id));
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $this->assertEmpty(groups_get_all_groups($course3->id));
        $this->assertEmpty(groups_get_all_groups($course4->id));
        $this->assertEmpty(groups_get_all_groups($course5->id));
        $this->assertEmpty(groups_get_all_groups($course6->id));

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals(0, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course3->id, $pgroup2->courseid);
        $this->assertEquals(0, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course5->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        // --------------------------------
        // Update the group definition.
        // --------------------------------
        $course->groups = json_decode($this->altgroupdata);
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 8 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(8, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);
        $course5 = array_shift($courses);
        $course6 = array_shift($courses);
        $course7 = array_shift($courses);
        $course8 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        // PGroup 1.
        $this->assertEquals('Test course creation (Renamed Group1)', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);

        $this->assertEquals('Test course creation (Renamed Group1)', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);

        // PGroup 2.
        $this->assertEquals('Test course creation (Group 1)', $course3->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);

        $this->assertEquals('Test course creation (Group 1)', $course4->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course4->category);

        // PGroup 3.
        $this->assertEquals('Test course creation (Adding a title to group 3)', $course5->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course5->category);

        $this->assertEquals('Test course creation (Adding a title to group 3)', $course6->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course6->category);

        // PGroup 4.
        $this->assertEquals('Test course creation (Newly added group 4)', $course7->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course7->category);

        $this->assertEquals('Test course creation (Newly added group 4)', $course8->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course8->category);

        // PGroup 1.
        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 2.
        $this->assertFalse(course::check_redirect($course3->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course3->id));
        $actualredirect = course::check_redirect($course4->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 3.
        $this->assertFalse(course::check_redirect($course5->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course5->id));
        $actualredirect = course::check_redirect($course6->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 4.
        $this->assertFalse(course::check_redirect($course7->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course7->id));
        $actualredirect = course::check_redirect($course8->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course1->id));
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $this->assertEmpty(groups_get_all_groups($course3->id));
        $this->assertEmpty(groups_get_all_groups($course4->id));
        $this->assertEmpty(groups_get_all_groups($course5->id));
        $this->assertEmpty(groups_get_all_groups($course6->id));
        $this->assertEmpty(groups_get_all_groups($course7->id));
        $this->assertEmpty(groups_get_all_groups($course8->id));

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(4, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);
        $pgroup4 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Renamed Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals(0, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Group 1', $pgroup2->grouptitle);
        $this->assertEquals($course3->id, $pgroup2->courseid);
        $this->assertEquals(0, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Adding a title to group 3', $pgroup3->grouptitle);
        $this->assertEquals($course5->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        $this->assertEquals('3', $pgroup4->groupnum);
        $this->assertEquals('abc_1234', $pgroup4->cmscourseid);
        $this->assertEquals('Newly added group 4', $pgroup4->grouptitle);
        $this->assertEquals($course7->id, $pgroup4->courseid);
        $this->assertEquals(0, $pgroup4->groupid);
    }

    public function test_parallelgroups_separatelecturers() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_LECTURERS;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 4 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(4, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        // PGroup 1 + 2.
        $this->assertEquals('Test course creation (Humphrey Bogart)', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);

        $this->assertEquals('Test course creation (Humphrey Bogart)', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);

        // PGroup 3.
        $this->assertEquals('Test course creation ()', $course3->fullname); // No lecturer specified.
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);

        $this->assertEquals('Test course creation ()', $course4->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course4->category);

        // PGroup 1 + 2.
        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 2.
        $this->assertFalse(course::check_redirect($course3->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course3->id));
        $actualredirect = course::check_redirect($course4->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $this->assertEmpty(groups_get_all_groups($course3->id));
        $this->assertEmpty(groups_get_all_groups($course4->id));
        $groups = groups_get_all_groups($course1->id);
        ksort($groups);
        $this->assertCount(2, $groups);
        $group1 = array_shift($groups);
        $group2 = array_shift($groups);

        $this->assertEquals('Test Group1', $group1->name);
        $this->assertEquals('Test Group2', $group2->name);

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals($group1->id, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals($group2->id, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course3->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        // --------------------------------
        // Update the group definition.
        // --------------------------------
        $course->groups = json_decode($this->altgroupdata);
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 6 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(6, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);
        $course5 = array_shift($courses);
        $course6 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        // PGroup 1 + 2.
        $this->assertEquals('Test course creation (Humphrey Bogart)', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);

        $this->assertEquals('Test course creation (Humphrey Bogart)', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);

        // PGroup 3.
        $this->assertEquals('Test course creation ()', $course3->fullname); // No lecturer specified.
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);

        $this->assertEquals('Test course creation ()', $course4->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course4->category);

        // PGroup 4.
        $this->assertEquals('Test course creation (Sam Spade)', $course5->fullname); // No lecturer specified.
        $this->assertEquals($this->directory[0]->get_category_id(), $course5->category);

        $this->assertEquals('Test course creation (Sam Spade)', $course6->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course6->category);

        // PGroup 1 + 2.
        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 3.
        $this->assertFalse(course::check_redirect($course3->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course3->id));
        $actualredirect = course::check_redirect($course4->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 4.
        $this->assertFalse(course::check_redirect($course5->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course5->id));
        $actualredirect = course::check_redirect($course6->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $this->assertEmpty(groups_get_all_groups($course3->id));
        $this->assertEmpty(groups_get_all_groups($course4->id));
        $this->assertEmpty(groups_get_all_groups($course5->id));
        $this->assertEmpty(groups_get_all_groups($course6->id));
        $groups = groups_get_all_groups($course1->id);
        ksort($groups);
        $this->assertCount(2, $groups);
        $group1 = array_shift($groups);
        $group2 = array_shift($groups);

        $this->assertEquals('Renamed Group1', $group1->name);
        $this->assertEquals('Group 1', $group2->name);

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(4, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);
        $pgroup4 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Renamed Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals($group1->id, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Group 1', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals($group2->id, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Adding a title to group 3', $pgroup3->grouptitle);
        $this->assertEquals($course3->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);

        $this->assertEquals('3', $pgroup4->groupnum);
        $this->assertEquals('abc_1234', $pgroup4->cmscourseid);
        $this->assertEquals('Newly added group 4', $pgroup4->grouptitle);
        $this->assertEquals($course5->id, $pgroup4->courseid);
        $this->assertEquals(0, $pgroup4->groupid);
    }

    public function test_parallelgroups_separatecourses_to_separategroups() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 2 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(6, $courses); // Existing courses are not deleted during change.
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        $this->assertEquals('abc_1234', $course1->shortname);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertContains('Synergy Learning', $course1->summary);

        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $this->assertContains('Synergy Learning', $course2->summary);

        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check correct Moodle groups have been created.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $groups = groups_get_all_groups($course1->id);
        ksort($groups);
        $this->assertCount(3, $groups);
        $group1 = array_shift($groups);
        $group2 = array_shift($groups);
        $group3 = array_shift($groups);

        $this->assertEquals('Test Group1', $group1->name);
        $this->assertEquals('Test Group2', $group2->name);
        $this->assertEquals('Group 2', $group3->name); // ID used when no title available.

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals($group1->id, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course1->id, $pgroup2->courseid);
        $this->assertEquals($group2->id, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course1->id, $pgroup3->courseid);
        $this->assertEquals($group3->id, $pgroup3->groupid);
    }

    public function test_parallelgroups_separategroup_to_separatecourse() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_GROUPS;

        // Should be no courses before we process the request.
        $courses = $DB->get_records_select('course', 'id > 1', array(), '', 'id, fullname, shortname, category, summary');
        $this->assertEmpty($courses);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);
        $course->groupScenario = parallelgroups::PGROUP_SEPARATE_COURSES;
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);

        // Should now be 6 courses - check they are as expected.
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(6, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $course4 = array_shift($courses);
        $course5 = array_shift($courses);
        $course6 = array_shift($courses);

        // Check all the course settings have been mapped as expected.
        // PGroup 1.
        $this->assertEquals('Test course creation (Test Group1)', $course1->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);

        $this->assertEquals('Test course creation (Test Group1)', $course2->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);

        // PGroup 2.
        $this->assertEquals('Test course creation (Test Group2)', $course3->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);

        $this->assertEquals('Test course creation (Test Group2)', $course4->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course4->category);

        // PGroup 3.
        $this->assertEquals('Test course creation (Group 2)', $course5->fullname);
        $this->assertEquals($this->directory[0]->get_category_id(), $course5->category);

        $this->assertEquals('Test course creation (Group 2)', $course6->fullname);
        $this->assertEquals($this->directory[1]->get_category_id(), $course6->category);

        // PGroup 1.
        $this->assertFalse(course::check_redirect($course1->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 2.
        $this->assertFalse(course::check_redirect($course3->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course3->id));
        $actualredirect = course::check_redirect($course4->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // PGroup 3.
        $this->assertFalse(course::check_redirect($course5->id)); // No redirect for the real course.
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $course5->id));
        $actualredirect = course::check_redirect($course6->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out()); // Link redirects to the real course.

        // Check no Moodle groups have been created.
        $this->assertCount(3, groups_get_all_groups($course1->id)); // Groups not deleted when the scenario changes.
        $this->assertEmpty(groups_get_all_groups($course2->id));
        $this->assertEmpty(groups_get_all_groups($course3->id));
        $this->assertEmpty(groups_get_all_groups($course4->id));
        $this->assertEmpty(groups_get_all_groups($course5->id));
        $this->assertEmpty(groups_get_all_groups($course6->id));

        // Check parallel groups records have been created.
        $pgroups = $DB->get_records('local_campusconnect_pgroup');
        $this->assertCount(3, $pgroups);
        $pgroup1 = array_shift($pgroups);
        $pgroup2 = array_shift($pgroups);
        $pgroup3 = array_shift($pgroups);

        $this->assertEquals('0', $pgroup1->groupnum);
        $this->assertEquals('abc_1234', $pgroup1->cmscourseid);
        $this->assertEquals('Test Group1', $pgroup1->grouptitle);
        $this->assertEquals($course1->id, $pgroup1->courseid);
        $this->assertEquals(0, $pgroup1->groupid);

        $this->assertEquals('1', $pgroup2->groupnum);
        $this->assertEquals('abc_1234', $pgroup2->cmscourseid);
        $this->assertEquals('Test Group2', $pgroup2->grouptitle);
        $this->assertEquals($course3->id, $pgroup2->courseid);
        $this->assertEquals(0, $pgroup2->groupid);

        $this->assertEquals('2', $pgroup3->groupnum);
        $this->assertEquals('abc_1234', $pgroup3->cmscourseid);
        $this->assertEquals('Group 2', $pgroup3->grouptitle);
        $this->assertEquals($course5->id, $pgroup3->courseid);
        $this->assertEquals(0, $pgroup3->groupid);
    }
}