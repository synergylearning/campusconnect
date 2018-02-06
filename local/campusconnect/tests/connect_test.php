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
 * Tests for main connection class for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\connect;
use local_campusconnect\ecssettings;
use local_campusconnect\event;

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
 * Class local_campusconnect_connect_test
 * @group local_campusconnect
 */
class local_campusconnect_connect_test extends advanced_testcase {
    /**
     * @var connect[]
     */
    protected $connect = array();
    /**
     * @var integer[]
     */
    protected $mid = array();

    protected function setUp() {

        if (defined('SKIP_CAMPUSCONNECT_CONNECT_TESTS')) {
            $this->markTestSkipped('Skipping connect tests, to save time');
            return;
        }

        $this->resetAfterTest();

        // Create the connections for testing
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
            $this->connect[$key] = new connect($ecs);
        }

        // Retrieve the mid values for each participant
        foreach ($this->connect as $key => $connect) {
            $memberships = $connect->get_memberships();
            foreach ($memberships[0]->participants as $participant) {
                if ($participant->itsyou) {
                    $this->mid[$key] = $participant->mid;
                    break;
                }
            }
        }
    }

    protected function tearDown() {
        // Delete all resources (just in case)
        foreach ($this->connect as $connect) {
            $courselinks = $connect->get_resource_list(event::RES_COURSELINK);
            foreach ($courselinks->get_ids() as $eid) {
                // All courselinks were created by 'unittest1'
                $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);
            }
        }

        // Delete all events
        foreach ($this->connect as $connect) {
            while ($connect->read_event_fifo(true)) {
                ;
            }
        }

        $this->connect = array();
        $this->mid = array();
    }

    public function test_get_memberships() {
        $result = $this->connect[1]->get_memberships();

        // Test that 'unittest1' is a member of only the community 'unittest'
        // with 3 other participants
        $this->assertInternalType('array', $result);
        $this->assertEquals(1, count($result));
        $this->assertEquals('unittest', $result[0]->community->name);
        $this->assertEquals(3, count($result[0]->participants));

        // Test that the 3 unittest participants are found in the community
        // and that 'Unit test 1' is identified as 'unittest1'
        $found = array(1 => false, 2 => false, 3 => false);
        foreach ($result[0]->participants as $participant) {
            if ($participant->name == 'Unit test 1') {
                $this->assertEquals(1, $participant->itsyou);
                $found[1] = true;
            } else if ($participant->name == 'Unit test 2') {
                $found[2] = true;
            } else if ($participant->name == 'Unit test 3') {
                $found[3] = true;
            }
        }
        foreach ($found as $participantfound) {
            $this->assertTrue($participantfound);
        }
    }

    public function test_auth() {
        $url = 'http://www.example.com/test123/';
        $params = 'param1param2param3';
        $realm = sha1($url.$params);
        $post = (object)array('realm' => $realm);

        // Retrieve an auth hash for connecting from 'unittest1' to 'unittest2'
        $hash = $this->connect[1]->add_auth($post, $this->mid[2]);

        // Test that 'unittest1' can confirm this hash
        $result = $this->connect[1]->get_auth($hash);
        $this->assertEquals($hash, $result->hash);
        $this->assertEquals($realm, $result->realm);

        // Retrieve a new auth hash for connecting from 'unittest1' to 'unittest2'
        $hash = $this->connect[1]->add_auth($post, $this->mid[2]);

        // Test that 'unittest2' can confirm this hash
        $result = $this->connect[2]->get_auth($hash);
        $this->assertEquals($hash, $result->hash);
        $this->assertEquals($realm, $result->realm);
        $this->assertEquals($this->mid[1], $result->mid);

        // Test that 'unittest2' cannot confirm this hash as second time
        $this->setExpectedException('\local_campusconnect\connect_exception');
        $this->connect[2]->get_auth($hash);

        // Test that 'unittest3' cannot retrieve this hash
        $hash = $this->connect[1]->add_auth($post, $this->mid[2]);
        $this->setExpectedException('\local_campusconnect\connect_exception');
        $this->connect[3]->get_auth($hash);
    }

    public function test_add_delete_resource() {
        $url = 'http://www.example.com/test123/';
        $post = (object)array('url' => $url);
        $community = 'unittest';

        // Add the resource - the response should be an integer > 0
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $post, $community);
        $this->assertInternalType('integer', $eid);
        $this->assertTrue($eid > 0);

        // Get the resource - should match the details specified at the top of this function
        $result = $this->connect[1]->get_resource($eid, event::RES_COURSELINK);
        $this->assertInstanceOf('stdClass', $result);
        $this->assertEquals($url, $result->url);

        // Get the resource details - should be sent / owned by mid[1] and received by mid[2] & mid[3]
        $result = $this->connect[1]->get_resource($eid, event::RES_COURSELINK,
                                                  connect::TRANSFERDETAILS);
        $this->assertInstanceOf('\local_campusconnect\details', $result);
        $this->assertTrue($result->sent_by_me(array($this->mid[1])));
        $recipientids = array($this->mid[2], $this->mid[3]);
        foreach ($recipientids as $recipientid) {
            $this->assertTrue($result->received_by($recipientid));
        }

        // Delete the resource
        $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);

        // Check the resource does not exist any more
        $result = $this->connect[1]->get_resource($eid, event::RES_COURSELINK,
                                                  connect::CONTENT);
        $this->assertFalse($result);
    }

    public function test_read_event_fifo() {
        $url = 'http://www.example.com/test123/';
        $post = (object)array('url' => $url);
        $community = 'unittest';

        // Check the event queue is empty
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);

        // Add a resource
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $post, $community);

        // Check there is a create event in the queue
        $result = $this->connect[2]->read_event_fifo();
        $this->assertInternalType('array', $result);
        $this->assertEquals(1, count($result));
        $this->assertInstanceOf('stdClass', $result[0]);
        $this->assertEquals('created', $result[0]->status);
        $this->assertEquals("campusconnect/courselinks/$eid", $result[0]->ressource);

        // Check the event is still there if not deleted
        $result = $this->connect[2]->read_event_fifo();
        $this->assertInternalType('array', $result);
        $this->assertEquals(1, count($result));
        $this->assertInstanceOf('stdClass', $result[0]);
        $this->assertEquals('created', $result[0]->status);
        $this->assertEquals("campusconnect/courselinks/$eid", $result[0]->ressource);

        // Check the event queue is empty after deletion
        $this->connect[2]->read_event_fifo(true);
        $result = $this->connect[2]->read_event_fifo();
        $this->assertEmpty($result);

        // Delete the resource
        $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);

        // Check there is now a deleted event
        $result = $this->connect[2]->read_event_fifo();
        $this->assertInternalType('array', $result);
        $this->assertEquals(1, count($result));
        $this->assertInstanceOf('stdClass', $result[0]);
        $this->assertEquals('destroyed', $result[0]->status);
        $this->assertEquals("campusconnect/courselinks/$eid", $result[0]->ressource);
    }

    public function test_get_resource_list() {
        $url = 'http://www.example.com/test123/';
        $post = (object)array('url' => $url);

        // Check the resource list is empty to begin with
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertInstanceOf('\local_campusconnect\uri_list', $result);
        $this->assertEmpty($result->get_ids());
        $result = $this->connect[3]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids());

        // Add a resource (only shared with 'unittest2')
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $post, null, $this->mid[2]);

        // Check 'unittest2' can see the new resource, but not 'unittest3'
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $ids = $result->get_ids();
        $this->assertEquals(1, count($ids));
        $this->assertEquals($eid, $ids[0]);
        $result = $this->connect[3]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids());

        // Delete the resource
        $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);

        // Check 'unittest2' can no longer see the resource
        $result = $this->connect[2]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($result->get_ids());
    }

    public function test_update_resource() {
        $url = 'http://www.example.com/test123/';
        $post = (object)array('url' => $url);
        $community = 'unittest';

        $url2 = 'http://www.example.com/updatetesting/';
        $post2 = (object)array('url' => $url2);

        // Add a resource
        $eid = $this->connect[1]->add_resource(event::RES_COURSELINK, $post, $community);

        // Get the resource - should match the details specified at the top of this function
        $result = $this->connect[2]->get_resource($eid, event::RES_COURSELINK,
                                                  connect::CONTENT);
        $this->assertInstanceOf('stdClass', $result);
        $this->assertEquals($url, $result->url);

        // Update the resource
        $this->connect[1]->update_resource($eid, event::RES_COURSELINK, $post2, $community);

        // Get the resource - should match the second set of details
        $result = $this->connect[2]->get_resource($eid, event::RES_COURSELINK,
                                                  connect::CONTENT);
        $this->assertInstanceOf('stdClass', $result);
        $this->assertEquals($url2, $result->url);

        // Double-check 'unittest2' cannot update the resource
        $this->setExpectedException('\local_campusconnect\connect_exception');
        $this->connect[2]->update_resource($eid, event::RES_COURSELINK, $post2, $community);

        // Delete the resource
        $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);
    }
}
