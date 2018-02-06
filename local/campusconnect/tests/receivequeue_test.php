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
use local_campusconnect\receivequeue;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/local/campusconnect/tests/testbase.php');

class local_campusconnect_receivequeue_test extends campusconnect_base_testcase {
    protected $resources = array();
    /** @var receivequeue */
    protected $queue = null;


    public function setUp() {
        parent::setUp();

        // Data for test resources to create.
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

        // General settings used by the tests.
        $this->queue = new receivequeue();
    }

    public function tearDown() {
        $this->clear_ecs_resources(event::RES_COURSELINK);
        $this->connect = array();
        $this->mid = array();
    }

    public function test_update_from_ecs_empty() {
        global $DB;

        // Check the update queue - no updates expected.
        $this->assertEmpty($DB->get_records('local_campusconnect_eventin'));
        $this->queue->update_from_ecs($this->connect[2]);
        $this->assertEmpty($DB->get_records('local_campusconnect_eventin'));
    }

    public function test_update_from_ecs_create_update_delete() {
        // Add a resource to the community.
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);

        // Set up the expectations - 3 records inserted, none deleted/updated.
        $expecteddata = array();
        $expecteddata[0] = (object)array('type' => 'campusconnect/courselinks',
                                         'resourceid' => "$eid",
                                         'serverid' => $this->connect[2]->get_ecs_id(),
                                         'status' => event::STATUS_CREATED);
        $expecteddata[1] = clone $expecteddata[0];
        $expecteddata[1]->status = event::STATUS_UPDATED;
        $expecteddata[2] = clone $expecteddata[0];
        $expecteddata[2]->status = event::STATUS_DESTROYED;

        // Check there is an event in the queue on the server.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertTrue(is_array($result));
        $this->assertNotEmpty($result);

        // Check the event is transferred correctly into the queue.
        // Expect first 'insert_record' call.
        $this->queue->update_from_ecs($this->connect[2]);

        // Check there are no events in the queue any more.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);

        // Update the resource.
        $this->connect[1]->update_resource($eid, event::RES_COURSELINK, $this->resources[2], $this->community);

        // Check there is an event in the queue on the server.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertTrue(is_array($result));
        $this->assertNotEmpty($result);

        // Check the event is transferred correctly into the queue.
        // Expect second 'insert_record' call.
        $this->queue->update_from_ecs($this->connect[2]);

        // Check there are no events in the queue any more.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);

        // Delete the resource.
        $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);

        // Check there is an event in the queue on the server.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertTrue(is_array($result));
        $this->assertNotEmpty($result);

        // Check the event is transferred correctly into the queue.
        // Expect third 'insert_record' call.
        $this->queue->update_from_ecs($this->connect[2]);

        // Check there are no events in the queue any more.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);
    }

    public function test_update_from_ecs_create_two() {
        global $DB;

        // Create two resources.
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);
        $eid2 = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[2], $this->community);

        // Check there is at least one event in the queue on the server.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertTrue(is_array($result));
        $this->assertNotEmpty($result);

        // Check the event is transferred correctly into the queue.
        $this->queue->update_from_ecs($this->connect[2]);

        $records = $DB->get_records('local_campusconnect_eventin', [], 'id');
        $record = array_shift($records);
        unset($record->id, $record->failcount);
        $this->assertEquals((object)array('type' => 'campusconnect/courselinks',
                                          'resourceid' => "$eid",
                                          'serverid' => $this->connect[2]->get_ecs_id(),
                                          'status' => event::STATUS_CREATED), $record);
        $record = array_shift($records);
        unset($record->id, $record->failcount);
        $this->assertEquals((object)array('type' => 'campusconnect/courselinks',
                                          'resourceid' => "$eid2",
                                          'serverid' => $this->connect[2]->get_ecs_id(),
                                          'status' => event::STATUS_CREATED), $record);

        // Check there are no events in the queue any more.
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);
    }

    /*
    public function test_process_event_queue_create() {
        global $DB;

        // Send courselink from server 1 to server 2 and check that a course
        // and courselink is correctly created on server 2

        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);

        // Mock up the event in the queue (adding events already tested above)
        $eventdata = (object)array('id' => 1,
                                   'type' => 'campusconnect/courselinks',
                                   'resourceid' => "$eid",
                                   'serverid' => $this->connect[2]->get_ecs_id(),
                                   'status' => event::STATUS_CREATED);
        $DB->setReturnValue('get_records_select', array()); // No further events.
        $DB->setReturnValue('get_records', array()); // Category mapping
        $DB->setReturnValueAt(0, 'get_records', array()); // Default metadata mappings.
        $DB->setReturnValueAt(0, 'get_records_select', array($eventdata)); // Get event.
        $DB->setReturnValueAt(1, 'get_record', false); // Check if courselink exists.
        $DB->setReturnValueAt(0, 'get_record', (object)array('id' => 1, 'import' => 1, 'export' => 1,
                                                              'importtype' => participantsettings::IMPORT_LINK)); // Load participant settings.
        $DB->setReturnValue('mock_create_course', 5); // Create course.
        $DB->setReturnValue('insert_record', 1); // Create course link.

        // Using the map_remote_to_course function to generate the comparison meta data
        // as that is what the process event queue function should be doing
        $metadata = new metadata($this->settings[2], true);
        $coursedata = $metadata->map_remote_to_course($this->resources[1]);
        $coursedata->summaryformat = FORMAT_HTML;
        $coursedata->category = $this->connect[2]->get_import_category();
        $linkdata = (object)array('courseid' => 5,
                                  'url' => $this->resources[1]->url,
                                  'resourceid' => "$eid",
                                  'ecsid' => $this->connect[2]->get_ecs_id(),
                                  'mid' => $this->mid[1]);
        $DB->expectOnce('mock_create_course', array($coursedata)); // Create course.
        $DB->expectOnce('insert_record', array('local_campusconnect_clink', $linkdata)); // Create course link.

        $DB->expectCallCount('get_records', 2); // Pulling items from the event queue
        $DB->expectCallCount('get_records_select', 2); //  Loading metadata settings
        $DB->expectCallCount('delete_records', 1); // Deleting items from the event queue

        $this->queue->process_queue();
    }

    public function test_process_event_queue_import_disabled() {
        global $DB;

        // Send courselink from server 1 to server 2 and check that a course
        // and courselink is correctly created on server 2

        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);

        // Mock up the event in the queue (adding events already tested above)
        $eventdata = (object)array('id' => 1,
                                   'type' => 'campusconnect/courselinks',
                                   'resourceid' => "$eid",
                                   'serverid' => $this->connect[2]->get_ecs_id(),
                                   'status' => event::STATUS_CREATED);
        $DB->setReturnValue('get_records_select', array()); // No further events.
        $DB->setReturnValue('get_records', array()); // Category mapping
        $DB->setReturnValueAt(0, 'get_records_select', array($eventdata)); // Get event.
        $DB->setReturnValueAt(1, 'get_record', false); // Check if courselink exists.
        $DB->setReturnValueAt(0, 'get_record', (object)array('id' => 1, 'import' => 0, 'export' => 1,
                                                             'importtype' => participantsettings::IMPORT_LINK)); // Load participant settings.

        $DB->expectNever('mock_create_course', 'Import disabled - did not expect a course to be created');
        $DB->expectNever('insert_record', 'Import disabled - did not expect a course link to be created');

        $DB->expectCallCount('get_records_select', 2); // Pulling items from the event queue
        $DB->expectCallCount('delete_records', 1); // Deleting items from the event queue

        $this->queue->process_queue();
    }

    public function test_process_event_queue_update() {
        global $DB;

        // Update a courselink sent by server 1 and received by server 2
        // and check course and courselink are correctly updated

        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);

        // Mock up the event in the queue (adding events already tested above)
        $eventdata = (object)array('id' => 1,
                                   'type' => 'campusconnect/courselinks',
                                   'resourceid' => "$eid",
                                   'serverid' => $this->connect[2]->get_ecs_id(),
                                   'status' => event::STATUS_UPDATED);
        $linkdata = (object)array('id' => 1,
                                  'courseid' => 5,
                                  'url' => $this->resources[2]->url, // Note the URL change (from resource[2], not resource[1])
                                  'resourceid' => "$eid",
                                  'ecsid' => $this->connect[2]->get_ecs_id(),
                                  'mid' => $this->mid[1]);
        $DB->setReturnValue('get_records_select', array()); // No further events.
        $DB->setReturnValue('get_records', array()); // Category mapping
        $DB->setReturnValueAt(0, 'get_records', array()); // Default metadata mappings.
        $DB->setReturnValueAt(0, 'get_records_select', array($eventdata)); // Get event.
        $DB->setReturnValueAt(1, 'get_record', $linkdata); // Retrieve courselink.
        $DB->setReturnValueAt(0, 'get_record', (object)array('id' => 1, 'import' => 1, 'export' => 1,
                                                             'importtype' => participantsettings::IMPORT_LINK)); // Load participant settings.
        $DB->setReturnValue('record_exists', true); // Check the course (that holds the link) still exists.

        // Using the map_remote_to_course function to generate the comparison meta data
        // as that is what the process event queue function should be doing
        $metadata = new metadata($this->settings[2], true);
        $coursedata = $metadata->map_remote_to_course($this->resources[1]);
        $coursedata->summaryformat = FORMAT_HTML;
        $coursedata->id = 5; // Courseid from the course link
        $linkdata = (object)array('id' => 1,
                                  'url' => $this->resources[1]->url);
        $DB->expectOnce('mock_update_course', array($coursedata)); // Update course.
        $DB->expectOnce('update_record', array('local_campusconnect_clink', $linkdata)); // Update course link.

        $DB->expectCallCount('get_records_select', 2); // Pulling items from the event queue
        $DB->expectCallCount('get_records', 2); // Metadata settings
        $DB->expectCallCount('delete_records', 1); // Deleting items from the event queue

        $this->queue->process_queue(null, true);
    }

    public function test_process_event_queue_delete() {
        global $DB;

        // Delete a courselink from server 1, received by course 2 and
        // check the course and courselink are correctly deleted

        $eid = 21;
        // Mock up the event in the queue (adding events already tested above)
        $eventdata = (object)array('id' => 1,
                                   'type' => 'campusconnect/courselinks',
                                   'resourceid' => "$eid",
                                   'serverid' => $this->connect[2]->get_ecs_id(),
                                   'status' => event::STATUS_DESTROYED);
        $linkdata = (object)array('id' => 3,
                                  'courseid' => 5,
                                  'url' => $this->resources[1]->url,
                                  'resourceid' => "$eid",
                                  'ecsid' => $this->connect[2]->get_ecs_id(),
                                  'mid' => $this->mid[1]);
        $DB->setReturnValue('get_records_select', array()); // No further events.
        $DB->setReturnValueAt(0, 'get_records_select', array($eventdata)); // Get event.
        $DB->setReturnValue('get_record', $linkdata); // Retrieve courselink

        $DB->expectOnce('mock_delete_course', array(5)); // Delete course.
        $DB->expectAt(0, 'delete_records', array('local_campusconnect_clink', array('id' => 3))); // Delete course link.

        $DB->expectCallCount('get_records_select', 2); // Pulling items from the event queue
        $DB->expectCallCount('delete_records', 2); // Deleting items from the event queue

        $this->queue->process_queue();
    }

    public function test_process_event_queue_update_course_deleted() {
        global $DB;

        // Update a courselink sent by server 1 and received by server 2
        // and check course and courselink are correctly updated

        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $this->resources[1], $this->community);

        // Mock up the event in the queue (adding events already tested above)
        $eventdata = (object)array('id' => 1,
                                   'type' => 'campusconnect/courselinks',
                                   'resourceid' => "$eid",
                                   'serverid' => $this->connect[2]->get_ecs_id(),
                                   'status' => event::STATUS_UPDATED);
        $linkdata = (object)array('id' => 1,
                                  'courseid' => 5,
                                  'url' => $this->resources[2]->url, // Note the URL change (from resource[2], not resource[1])
                                  'resourceid' => "$eid",
                                  'ecsid' => $this->connect[2]->get_ecs_id(),
                                  'mid' => $this->mid[1]);
        $DB->setReturnValue('get_records_select', array()); // No further events.
        $DB->setReturnValue('get_records', array()); // Category mapping
        $DB->setReturnValueAt(0, 'get_records', array()); // Default metadata mappings.
        $DB->setReturnValueAt(0, 'get_records_select', array($eventdata)); // Get event.
        $DB->setReturnValueAt(1, 'get_record', $linkdata); // Retrieve courselink.
        $DB->setReturnValueAt(0, 'get_record', (object)array('id' => 1, 'import' => 1, 'export' => 1,
                                                             'importtype' => participantsettings::IMPORT_LINK)); // Load participant settings.
        $DB->setReturnValue('record_exists', false); // Check the course (that holds the link) still exists.
        $DB->setReturnValue('mock_create_course', 6); // Create the course (as the old course has been deleted).

        // Using the map_remote_to_course function to generate the comparison meta data
        // as that is what the process event queue function should be doing
        $metadata = new metadata($this->settings[2], true);
        $coursedata = $metadata->map_remote_to_course($this->resources[1]);
        $coursedata->summaryformat = FORMAT_HTML;
        $coursedata->category = $this->connect[2]->get_import_category();
        $linkdata = (object)array('id' => 1,
                                  'courseid' => 6);
        $linkdata2 = (object)array('id' => 1,
                                   'url' => $this->resources[1]->url);
        $DB->expectOnce('mock_create_course', array($coursedata)); // Update course.
        $DB->expectAt(0, 'update_record', array('local_campusconnect_clink', $linkdata)); // Update course link (new courseid).
        $DB->expectAt(1, 'update_record', array('local_campusconnect_clink', $linkdata2)); // Update course link (new url).

        $DB->expectCallCount('get_records_select', 2); // Pulling items from the event queue
        $DB->expectCallCount('get_records', 2); // loading metadata settings
        $DB->expectCallCount('delete_records', 1); // Deleting items from the event queue

        $this->queue->process_queue();
    }
    */
}