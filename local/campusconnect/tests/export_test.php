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
 * Tests for ECS settings
 *
 * @package   local_campusconnect
 * @copyright 2016 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\event;
use local_campusconnect\export;
use local_campusconnect\participantsettings;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/local/campusconnect/tests/testbase.php');

class local_campusconnect_export_test extends campusconnect_base_testcase {
    protected $resources = array();

    public function setUp() {
        parent::setUp();

        // Data for test resources to create
        $this->resources[1] = (object)array('url' => 'http://www.example.com/test123',
                                            'title' => 'Course from ECS',
                                            'organization' => 'Synergy Learning',
                                            'lang' => 'en',
                                            'semesterHours' => '5',
                                            'courseID' => 'course5:220',
                                            'term' => 'WS 06/07',
                                            'credits' => '10',
                                            'status' => 'online',
                                            'courseType' => 'Vorlesung');

        $this->resources[2] = (object)array('url' => 'http://www.example.com/test456');

        // Enable export from ecs1 to each of the participants.
        foreach ($this->mid as $key => $mid) {
            $part = new participantsettings($this->connect[1]->get_settings()->get_id(), $mid);
            $part->save_settings(array('export' => true));
        }
    }

    protected function tearDown() {
        $this->clear_ecs_resources(event::RES_COURSELINK);

        $this->connect = array();
        $this->mid = array();
    }

    public function test_list_participants() {
        $export = new export(-10);
        /** @var $participants participantsettings[] */
        $participants = $export->list_participants();

        $this->assertTrue(count($participants) >= 3, 'Should be at least 3 participants to export to');
        $found = array(1 => false, 2 => false, 3 => false);
        foreach ($participants as $part) {
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                $idx = array_search($part->get_mid(), $this->mid);
                $this->assertTrue($idx !== false, 'Found potential export participant in this ECS that is not in expected list');
                $found[$idx] = true;
            }
        }
        foreach ($found as $idx => $ok) {
            $this->assertTrue($ok, "Participant $idx not found in the list of potential participants");
        }
    }

    public function test_set_export() {
        $export = new export(-10);
        // Check this course is exported to no participants at the start.
        $this->assertFalse($export->is_exported(), 'Course should not currently be exported');
        $this->assertCount(0, $export->list_current_exports(), 'Course exported participants list should be empty');

        // Check exporting to one participant works as expected.
        /** @var $potentialexports participantsettings[] */
        $potentialexports = $export->list_participants();
        /** @var $potential participantsettings[] */
        $potential = array();
        foreach ($potentialexports as $part) {
            // Ignore any potential exports that are not part of the unit-testing environment.
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                $potential[] = $part;
            }
        }
        $export->set_export($potential[0]->get_identifier(), true);
        $this->assertTrue($export->is_exported(), 'Course should now be marked as exported');
        $exports = $export->list_current_exports();
        $this->assertCount(1, $exports, 'Course should be exported to one participant only');
        $this->assertTrue(isset($exports[$potential[0]->get_identifier()]), 'Expected the export to match the participant we exported to');
        $potentialexports = $export->list_participants();
        foreach ($potentialexports as $part) {
            // Ignore any potential exports that are not part of the unit-testing environment.
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                if ($part->get_mid() == $potential[0]->get_mid()) {
                    $this->assertTrue($part->is_exported(), 'Expected this participant to be exported to');
                } else {
                    $this->assertFalse($part->is_exported(), 'Expected this participant to NOT be exported to');
                }
            }
        }

        // Check that re-loading the export settings works
        $export = new export(-10);
        $this->assertTrue($export->is_exported(), 'Course should now be marked as exported');
        $exports = $export->list_current_exports();
        $this->assertCount(1, $exports, 'Course should be exported to one participant only');
        $this->assertTrue(isset($exports[$potential[0]->get_identifier()]), 'Expected the export to match the participant we exported to');
        $potentialexports = $export->list_participants();
        foreach ($potentialexports as $part) {
            // Ignore any potential exports that are not part of the unit-testing environment.
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                if ($part->get_mid() == $potential[0]->get_mid()) {
                    $this->assertTrue($part->is_exported(), 'Expected this participant to be exported to');
                } else {
                    $this->assertFalse($part->is_exported(), 'Expected this participant to NOT be exported to');
                }
            }
        }

        // Check that setting a second setting works.
        $export->set_export($potential[1]->get_identifier(), true);
        $this->assertTrue($export->is_exported(), 'Course should now be marked as exported');
        $exports = $export->list_current_exports();
        $this->assertCount(2, $exports, 'Course should be exported to two participants');
        $this->assertTrue(isset($exports[$potential[0]->get_identifier()]), 'Expected the export to match the participant we exported to');
        $this->assertTrue(isset($exports[$potential[1]->get_identifier()]), 'Expected the export to match the participant we exported to');

        // Check that clearing a setting works.
        $export->set_export($potential[0]->get_identifier(), false);
        $this->assertTrue($export->is_exported(), 'Course should now be marked as exported');
        $exports = $export->list_current_exports();
        $this->assertCount(1, $exports, 'Course should be exported to two participants');
        $this->assertTrue(isset($exports[$potential[1]->get_identifier()]), 'Expected the export to match the participant we exported to');

        // Check that clearing both settings works.
        $export->set_export($potential[1]->get_identifier(), false);
        $this->assertFalse($export->is_exported(), 'Course should now be marked as NOT exported');
        $exports = $export->list_current_exports();
        $this->assertCount(0, $exports, 'Course should be exported to two participants');
    }

    public function test_clear_exports() {
        $export = new export(-10);

        /** @var $potentialexports participantsettings[] */
        $potentialexports = $export->list_participants();
        /** @var $potential participantsettings[] */
        $potential = array();
        foreach ($potentialexports as $part) {
            // Ignore any potential exports that are not part of the unit-testing environment.
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                $potential[] = $part;
            }
        }
        // Set the exports.
        $export->set_export($potential[0]->get_identifier(), true);
        $export->set_export($potential[1]->get_identifier(), true);

        // Clear the exports.
        $export->clear_exports();

        // Check the export list is immediately empty.
        $this->assertFalse($export->is_exported(), 'Course should now be marked as NOT exported');
        $exports = $export->list_current_exports();
        $this->assertCount(2, $exports, 'Course should be exported to two participants');

        // Check the export list is empty after reloading it.
        $export = new export(-10);
        $this->assertFalse($export->is_exported(), 'Course should now be marked as NOT exported');
        $exports = $export->list_current_exports();
        $this->assertCount(0, $exports, 'Course should be exported to two participants');
    }

    public function test_update_ecs_empty() {
        // Check that there are no courses currently exported.
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to be no exported courses');

        // Update the ECS with exported courses - should be nothing to export.
        export::update_ecs($this->connect[1]);
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to still be no exported courses');
    }

    public function test_update_ecs_exported() {
        global $CFG;

        $export = new export(-10);

        $exportcourse = (object)array('id' => -10,
                                      'fullname' => 'testexport',
                                      'shortname' => 'testexport',
                                      'startdate' => mktime(12, 0, 0, 4, 1, 2012),
                                      'visible' => 1);
        $coursedata = array(-10 => $exportcourse);

        /** @var $potentialexports participantsettings[] */
        $potentialexports = $export->list_participants();
        /** @var $potential participantsettings[] */
        $potential = array();
        foreach ($potentialexports as $part) {
            // Ignore any potential exports that are not part of the unit-testing environment.
            if ($part->get_ecs_id() == $this->connect[1]->get_settings()->get_id()) {
                $idx = array_search($part->get_mid(), $this->mid);
                $this->assertTrue($idx !== false, 'Unexpected participant in the unit test community');
                $potential[$idx] = $part;
            }
        }
        // Export the course from ECS 1 to ECS 2.
        $export->set_export($potential[2]->get_identifier(), true);

        // Check there are still no exported courses.
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to be no exported courses for ECS 2');
        $result = $this->connect[3]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to be no exported courses for ECS 3');

        // Update the ECS.
        export::update_ecs($this->connect[1], $coursedata);

        // Check the expected course is now available.
        $result = $this->connect[3]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to still be no exported courses for ECS 3');
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $ids = $result->get_ids();
        $this->assertCount(1, $ids, 'Expected there to now be exported courses for ECS 2');
        $result = $this->connect[2]->get_resource($ids[0], event::RES_COURSELINK);

        $this->assertInstanceOf('stdClass', $result);
        $this->assertEquals($CFG->wwwroot.'/local/campusconnect/viewcourse.php?id=-10', $result->url, "Unexpected URL: {$result->url}");
        $this->assertEquals($exportcourse->fullname, $result->title, 'Exported title does not match the course fullname');
        $this->assertEquals('2012-04-01T12:00:00+0800', $result->firstDate, "Exported firstDate timestamp ({$result->firstDate}) does not match");

        // Check that removing the course from export works.
        $export = new export(-10); // Need to create a new object, otherwise changes from 'update_ecs' not recorded.
        $export->set_export($potential[2]->get_identifier(), false);
        export::update_ecs($this->connect[1], $coursedata);

        // Check the course is no longer available.
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to be no exported courses for ECS 2');
        $result = $this->connect[3]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids(), 'Expected there to be no exported courses for ECS 3');
    }
}