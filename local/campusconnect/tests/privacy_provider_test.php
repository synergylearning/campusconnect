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
 * Privacy provider tests
 *
 * @package   local_campusconnect
 * @copyright 2019 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\privacy\provider;
use core_privacy\local\metadata\collection;

defined('MOODLE_INTERNAL') || die();

class local_campusconnect_privacy_provider_testcase extends \core_privacy\tests\provider_testcase {
    protected $userwithrecord;
    protected $userwithrecord2;
    protected $userwithoutrecord;
    protected $course;

    /**
     * {@inheritdoc}
     */
    protected function setUp() {
        global $DB;
        $this->resetAfterTest();

        $gen = self::getDataGenerator();

        // Create course.
        $this->course = $gen->create_course();
        $ins = (object)[
            'courseid' => $this->course->id,
            'resourceid' => 101,
            'cmsid' => 'CRS01',
            'ecsid' => 5,
            'mid' => 11,
        ];
        $DB->insert_record('local_campusconnect_crs', $ins);

        // Create users.
        $this->userwithrecord = $gen->create_user();
        $this->userwithrecord2 = $gen->create_user();
        $this->userwithoutrecord = $gen->create_user();

        // Fake auth record, for testing purposes.
        $ins = (object)[
            'pids' => '5_6',
            'personid' => '12345',
            'username' => $this->userwithrecord->username,
            'lastenroled' => strtotime('2019-05-01T12:15:00Z'),
            'personidtype' => \local_campusconnect\courselink::PERSON_UID,
            'suspended' => 0,
        ];
        $DB->insert_record('auth_campusconnect', $ins);

        $ins = (object)[
            'pids' => '5_8',
            'personid' => '54321',
            'username' => $this->userwithrecord2->username,
            'lastenroled' => strtotime('2019-04-08T12:15:00Z'),
            'personidtype' => \local_campusconnect\courselink::PERSON_UID,
            'suspended' => 0,
        ];
        $DB->insert_record('auth_campusconnect', $ins);

        // Link the users as course members.
        $ins = (object)[
            'resourceid' => 102,
            'cmscourseid' => 'CRS01',
            'personid' => '12345',
            'personidtype' => \local_campusconnect\courselink::PERSON_UID,
            'role' => 'student',
            'status' => 0,
            'parallelgroups' => '',
        ];
        $DB->insert_record('local_campusconnect_mbr', $ins);

        $ins = (object)[
            'resourceid' => 103,
            'cmscourseid' => 'CRS01',
            'personid' => '54321',
            'personidtype' => \local_campusconnect\courselink::PERSON_UID,
            'role' => 'student',
            'status' => 0,
            'parallelgroups' => '',
        ];
        $DB->insert_record('local_campusconnect_mbr', $ins);
    }

    /**
     * Test for provider::get_metadata().
     */
    public function test_get_metadata() {
        $collection = new collection('local_campusconnect');
        $newcollection = provider::get_metadata($collection);
        $itemcollection = $newcollection->get_collection();
        $this->assertCount(1, $itemcollection);

        $table = array_shift($itemcollection);
        $this->assertEquals('local_campusconnect_mbr', $table->get_name());
        $privacyfields = $table->get_privacy_fields();
        $this->assertArrayHasKey('resourceid', $privacyfields);
        $this->assertArrayHasKey('cmscourseid', $privacyfields);
        $this->assertArrayHasKey('personid', $privacyfields);
        $this->assertArrayHasKey('personidtype', $privacyfields);
        $this->assertArrayHasKey('role', $privacyfields);
        $this->assertArrayHasKey('status', $privacyfields);
        $this->assertArrayHasKey('parallelgroups', $privacyfields);
        $this->assertEquals('privacy:metadata:local_campusconnect_mbr', $table->get_summary());
        foreach ($privacyfields as $field) {
            get_string($field, 'local_campusconnect');
        }
        get_string($table->get_summary(), 'local_campusconnect');
    }

    /**
     * Test for provider::get_contexts_for_userid().
     */
    public function test_get_contexts_for_userid() {
        $contexts = [
            context_course::instance($this->course->id)->id,
        ];
        $contextlist = provider::get_contexts_for_userid($this->userwithrecord->id);
        $this->assertEquals($contexts, $contextlist->get_contextids());

        $contextlist = provider::get_contexts_for_userid($this->userwithoutrecord->id);
        $this->assertEmpty($contextlist);
    }

    /**
     * Test for provider::export_user_data().
     */
    public function test_export_for_context() {
        $ctx = context_course::instance($this->course->id);

        // Export all of the data for the context.
        $this->export_context_data_for_user($this->userwithrecord->id, $ctx, 'local_campusconnect');
        $writer = \core_privacy\local\request\writer::with_context($ctx);
        $this->assertTrue($writer->has_any_data());

        \core_privacy\local\request\writer::reset();
        $this->export_context_data_for_user($this->userwithoutrecord->id, $ctx, 'local_campusconnect');
        $writer = \core_privacy\local\request\writer::with_context($ctx);
        $this->assertFalse($writer->has_any_data());
    }

    /**
     * Test for provider::delete_data_for_all_users_in_context().
     */
    public function test_delete_data_for_all_users_in_context() {
        global $DB;

        provider::delete_data_for_all_users_in_context(context_system::instance());
        $this->assertTrue($DB->record_exists('local_campusconnect_mbr', []));

        provider::delete_data_for_all_users_in_context(context_course::instance($this->course->id));
        $this->assertFalse($DB->record_exists('local_campusconnect_mbr', []));
    }

    /**
     * Test for provider::delete_data_for_user().
     */
    public function test_delete_data_for_user() {
        global $DB;

        $ctx = context_course::instance($this->course->id);
        $contextlist = new \core_privacy\local\request\approved_contextlist($this->userwithrecord, 'local_campusconnect',
                                                                            [$ctx->id]);
        provider::delete_data_for_user($contextlist);

        $recs = $DB->get_records('local_campusconnect_mbr', []);
        $this->assertCount(1, $recs);
        list($rec) = array_values($recs);
        $authrec = $DB->get_record('auth_campusconnect', ['username' => $this->userwithrecord2->username]);
        $this->assertEquals($authrec->personid, $rec->personid);
        $this->assertEquals($authrec->personidtype, $rec->personidtype);
    }

    public function test_get_users_in_context() {
        $ctx = context_course::instance($this->course->id);

        $userlist = new \core_privacy\local\request\userlist($ctx, 'local_campusconnect');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->userwithrecord->id, $this->userwithrecord2->id], $userlist->get_userids(), '', 0.0, 10, true);
    }

    public function test_delete_data_for_users() {
        $ctx = context_course::instance($this->course->id);

        $approvedlist = new \core_privacy\local\request\approved_userlist($ctx, 'local_campusconnect', [$this->userwithrecord->id]);
        provider::delete_data_for_users($approvedlist);

        $userlist = new \core_privacy\local\request\userlist($ctx, 'local_campusconnect');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->userwithrecord2->id], $userlist->get_userids());
    }
}
