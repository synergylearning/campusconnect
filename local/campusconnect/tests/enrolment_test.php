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
 * Test sending and receiving of enrolment status resources.
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\connect;
use local_campusconnect\courselink;
use local_campusconnect\ecssettings;
use local_campusconnect\enrolment;
use local_campusconnect\event;
use local_campusconnect\export;
use local_campusconnect\participantsettings;
use local_campusconnect\receivequeue;

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
 * Class local_campusconnect_enrolment_test
 * @group local_campusconnect
 */
class local_campusconnect_enrolment_test extends advanced_testcase {
    /** @var connect[] */
    protected $connect = array();
    /** @var integer[] */
    protected $mid = array();
    protected $pid = array();

    protected function setUp() {
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
            $this->connect[$key] = new connect($ecs);
        }

        // Retrieve the mid values for each participant.
        foreach ($this->connect as $key => $connect) {
            $communities = participantsettings::load_communities($connect->get_settings());
            $community = reset($communities);
            foreach ($community->participants as $participant) {
                /** \local_campusconnect\participantsettings $participant */
                if ($participant->is_me()) {
                    $this->mid[$key] = $participant->get_mid();
                    $this->pid[$key] = $participant->get_pid();
                    break;
                }
            }
        }

        $this->clear_ecs_resources();
    }

    protected function clear_ecs_resources() {
        foreach ($this->connect as $connect) {
            // Delete all enrolment statuses (in case there are some left over from a failed test).
            $enrolments = $connect->get_resource_list(event::RES_ENROLMENT, connect::SENT);
            foreach ($enrolments->get_ids() as $eid) {
                $this->connect[1]->delete_resource($eid, event::RES_ENROLMENT);
            }
            // Delete all courselinks.
            $courselinks = $this->connect[2]->get_resource_list(event::RES_COURSELINK, connect::SENT);
            foreach ($courselinks->get_ids() as $cid) {
                $this->connect[2]->delete_resource($cid, event::RES_COURSELINK);
            }
            // Delete all events.
            while ($connect->read_event_fifo(true)) {
                ;
            }
        }
    }

    protected function tearDown() {
        $this->clear_ecs_resources();

        $this->connect = array();
        $this->mid = array();
    }

    public function test_enrolment_status() {
        global $DB;

        // Note course link goest 'unittest2' => 'unittest1', user goes 'unittest1' => 'unittest2', status goes 'unittest2' => 'unittest1'.

        // Set 'unittest2' to export course links to 'unittest1'.
        $part1 = new participantsettings($this->connect[2]->get_ecs_id(), $this->mid[1]);
        $part1->save_settings(array('export' => true));

        // Set 'unittest1' to import course links from 'unittest2'.
        $part2 = new participantsettings($this->connect[1]->get_ecs_id(), $this->mid[2]);
        $part2->save_settings(array('import' => true, 'importtype' => participantsettings::IMPORT_LINK));

        // Make sure there are no 'enrolment status' or 'course link' resources waiting for 'unittest1'.
        $reslist = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertEmpty($reslist->get_ids());
        $reslist = $this->connect[1]->get_resource_list(event::RES_COURSELINK);
        $this->assertEmpty($reslist->get_ids());

        // Generate a course and export a course link from 'unittest2' => 'unittest1'.
        $srccourse = $this->getDataGenerator()->create_course(array(
                                                                  'fullname' => 'test full name', 'shortname' => 'test short name'
                                                              ));

        $export = new export($srccourse->id);
        $export->set_export($part1->get_identifier(), true);

        export::update_ecs($this->connect[2]); // Export.
        courselink::refresh_from_participant($this->connect[1]->get_ecs_id(), $this->mid[2]); // Import.

        $courselinks = $DB->get_records('local_campusconnect_clink', array(
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2]
        ));
        $this->assertCount(1, $courselinks); // Should only have imported 1 course link.
        $courselink = reset($courselinks);
        $dstcourseid = $courselink->courseid; // The course that represents the course link on 'unittest1'.
        $course = $DB->get_record('course', array('id' => $dstcourseid));
        $this->assertEquals('test full name', $course->fullname); // Make sure the correct course link has been created.

        // Generate a fake authenticated user who has followed a course link from 'unittest1' => 'unittest2'.
        $srcuser = $this->getDataGenerator()->create_user(array('username' => 'srcuser')); // User on 'unittest1'.
        $this->assertEmpty(enrol_get_all_users_courses($srcuser->id)); // Make sure the user (on 'unittest1') is not currently ...
        // ... enroled in any courses.
        $userdata = $part1->map_export_data($srcuser);
        $uid = $userdata['ecs_uid'];
        $authcc = get_auth_plugin('campusconnect');
        $class = new ReflectionClass('auth_plugin_campusconnect'); // Need to use reflection as the method is private.
        $usernamefromparams = $class->getMethod('username_from_params');
        $usernamefromparams->setAccessible(true);
        $username = $usernamefromparams->invoke($authcc, 'test_institution', 'test_username',
                                                courselink::PERSON_UID, $uid, // Unique user id from 'unittest1'.
                                                $this->connect[2]->get_ecs_id(), $this->pid[1], // From 'unittest1'.
                                                $part1);
        $dstuser = $this->getDataGenerator()->create_user(array(
                                                              'auth' => 'campusconnect', 'username' => $username
                                                          )); // User on 'unittest2'.

        // -------------------------------
        // Enrolment.
        // -------------------------------

        // Enrol this user in the 'real' course on 'unittest2'.
        $enrolman = enrol_get_plugin('manual');
        $enrolinst = $DB->get_record('enrol', array('enrol' => 'manual', 'courseid' => $srccourse->id), '*', IGNORE_MULTIPLE);
        $enrolman->enrol_user($enrolinst, $dstuser->id);

        // Check the enrolment status has been queued.
        $enrolstatus = $DB->get_records('local_campusconnect_enrex');
        $this->assertCount(1, $enrolstatus);
        $enrolstatus = reset($enrolstatus);
        $this->assertEquals($srccourse->id, $enrolstatus->courseid);
        $this->assertEquals($dstuser->id, $enrolstatus->userid);
        $this->assertEquals(enrolment::STATUS_ACTIVE, $enrolstatus->status);

        // Refresh the connection.
        enrolment::update_ecs($this->connect[2]); // Send status 'unittest2' => 'unittest1'.

        // Check the enrolment status is now gone.
        $this->assertEmpty($DB->get_records('local_campusconnect_enrex'));

        // Pull the enrolment status from 'unittest2' => 'unittest1'.
        $msgs = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertCount(1, $msgs->get_ids()); // Check just one 'enrolment status' notification has been received.

        $queue = new receivequeue();
        $queue->update_from_ecs($this->connect[1]);
        $queue->process_queue($this->connect[1]->get_settings());

        $msgs = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertEmpty($msgs->get_ids()); // Check there are now no 'enrolment status' notifications to be received.

        // Check the user is now enroled in the course link on 'unittest1'.
        $courses = enrol_get_all_users_courses($srcuser->id);
        $this->assertCount(1, $courses);
        $course = reset($courses);
        $this->assertEquals($dstcourseid, $course->id);

        // Check that no further messages are generated when 'unittest2' updates the ECS.
        enrolment::update_ecs($this->connect[2]);
        $msgs = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertEmpty($msgs->get_ids()); // Check there are now no 'enrolment status' notifications to be received.

        // -------------------------------
        // Unenrolment.
        // -------------------------------

        // Unenrol the user from the remote course.
        $enrolman->unenrol_user($enrolinst, $dstuser->id);

        // Check the enrolment status has been queued.
        $enrolstatus = $DB->get_records('local_campusconnect_enrex');
        $this->assertCount(1, $enrolstatus);
        $enrolstatus = reset($enrolstatus);
        $this->assertEquals($srccourse->id, $enrolstatus->courseid);
        $this->assertEquals($dstuser->id, $enrolstatus->userid);
        $this->assertEquals(enrolment::STATUS_UNSUBSCRIBED, $enrolstatus->status);

        // Refresh the connection.
        enrolment::update_ecs($this->connect[2]); // Send status 'unittest2' => 'unittest1'.

        // Check the enrolment status is now gone.
        $this->assertEmpty($DB->get_records('local_campusconnect_enrex'));

        // Pull the enrolment status from 'unittest2' => 'unittest1'.
        $msgs = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertCount(1, $msgs->get_ids()); // Check just one 'enrolment status' notification has been received.

        $queue = new receivequeue();
        $queue->update_from_ecs($this->connect[1]);
        $queue->process_queue($this->connect[1]->get_settings());

        $msgs = $this->connect[1]->get_resource_list(event::RES_ENROLMENT);
        $this->assertEmpty($msgs->get_ids()); // Check there are now no 'enrolment status' notifications to be received.

        // Check the user is no longer enroled in the course link on 'unittest1'.
        $this->assertEmpty(enrol_get_all_users_courses($srcuser->id));
    }
}