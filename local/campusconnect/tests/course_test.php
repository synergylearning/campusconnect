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
 * Class local_campusconnect_course_test
 * @group local_campusconnect
 */
class local_campusconnect_course_test extends advanced_testcase {
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

    public function setUp() {
        global $DB;

        if (defined('SKIP_CAMPUSCONNECT_COURSE_TESTS')) {
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
    }

    public function test_create_course() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);

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
    }

    public function test_update_course() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);

        // Create a course.
        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $this->assertEquals('Test course creation', $course1->fullname);
        $this->assertEquals('Test course creation', $course2->fullname);
        $this->assertContains('Synergy Learning', $course1->summary);
        $this->assertContains('Synergy Learning', $course2->summary);

        // Update the course details.
        $course->title = 'Test update title';
        $course->organisation = 'New organisation';
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $realcourse = $course1;
        $this->assertEquals('Test update title', $course1->fullname);
        $this->assertEquals('Test update title', $course2->fullname);
        $this->assertContains('New organisation', $course1->summary);
        $this->assertContains('New organisation', $course2->summary);
        $this->assertEquals($this->directory[0]->get_category_id(), $course1->category);
        $this->assertEquals($this->directory[1]->get_category_id(), $course2->category);
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $realcourse->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out());

        // Move the course to a new directory.
        $course->allocations = array(
            (object)array(
                'parentID' => $this->directory[1]->get_directory_id(),
            ),
            (object)array(
                'parentID' => $this->directory[2]->get_directory_id(),
            )
        );
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $this->assertEquals($this->directory[1]->get_category_id(), $course1->category);
        $this->assertEquals($this->directory[2]->get_category_id(), $course2->category);
        $this->assertEquals($realcourse->id, $course1->id);
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $realcourse->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out());

        // Add one more directory allocation.
        $course->allocations[] = (object)array(
            'parentID' => $this->directory[0]->get_directory_id(),
        );
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(3, $courses);
        $course1 = array_shift($courses);
        $course2 = array_shift($courses);
        $course3 = array_shift($courses);
        $this->assertEquals($this->directory[1]->get_category_id(), $course1->category);
        $this->assertEquals($this->directory[2]->get_category_id(), $course2->category);
        $this->assertEquals($this->directory[0]->get_category_id(), $course3->category);
        $this->assertEquals($realcourse->id, $course1->id);
        $expectedredirect = new moodle_url('/course/view.php', array('id' => $realcourse->id));
        $this->assertFalse(course::check_redirect($course1->id));
        $actualredirect = course::check_redirect($course2->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out());
        $actualredirect = course::check_redirect($course3->id);
        $this->assertEquals($expectedredirect->out(), $actualredirect->out());

        // Reduce to one directory allocation.
        $course->allocations = array(
            (object)array(
                'parentID' => $this->directory[2]->get_directory_id(),
            )
        );
        course::update($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(1, $courses);
        $course1 = array_shift($courses);
        $this->assertEquals($this->directory[2]->get_category_id(), $course1->category);
        $this->assertEquals($realcourse->id, $course1->id);
        $this->assertFalse(course::check_redirect($course1->id));
    }

    public function test_delete_course() {
        global $DB;

        // Course create request from participant 1 to participant 2.
        $resourceid = -10;
        $course = json_decode($this->coursedata);

        course::create($resourceid, $this->settings[2], $course, $this->transferdetails);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(2, $courses);
        $realcourse = array_shift($courses);
        $course2 = array_shift($courses);

        // Check the real course is not deleted, but the link course is.
        course::delete($resourceid, $this->settings[2]);
        $courses = $DB->get_records_select('course', 'id > 1', array(), 'id', 'id, fullname, shortname, category, summary');
        $this->assertCount(1, $courses);
        $course1 = array_shift($courses);
        $this->assertEquals($realcourse->id, $course1->id);
        $this->assertFalse(course::check_redirect($course2->id));
    }
}