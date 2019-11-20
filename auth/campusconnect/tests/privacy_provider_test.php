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
 * @package   auth_campusconnect
 * @copyright 2019 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use auth_campusconnect\privacy\provider;
use core_privacy\local\metadata\collection;

defined('MOODLE_INTERNAL') || die();

class auth_campusconnect_privacy_provider_testcase extends \core_privacy\tests\provider_testcase {
    protected $userwithrecord;
    protected $userwithrecord2;
    protected $userwithoutrecord;

    /**
     * {@inheritdoc}
     */
    protected function setUp() {
        global $DB;
        $this->resetAfterTest();

        $gen = self::getDataGenerator();
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
    }

    /**
     * Test for provider::get_metadata().
     */
    public function test_get_metadata() {
        $collection = new collection('auth_campusconnect');
        $newcollection = provider::get_metadata($collection);
        $itemcollection = $newcollection->get_collection();
        $this->assertCount(1, $itemcollection);

        $table = array_shift($itemcollection);
        $this->assertEquals('auth_campusconnect', $table->get_name());
        $privacyfields = $table->get_privacy_fields();
        $this->assertArrayHasKey('pids', $privacyfields);
        $this->assertArrayHasKey('personid', $privacyfields);
        $this->assertArrayHasKey('username', $privacyfields);
        $this->assertArrayHasKey('lastenroled', $privacyfields);
        $this->assertArrayHasKey('personidtype', $privacyfields);
        $this->assertArrayHasKey('suspended', $privacyfields);
        $this->assertEquals('privacy:metadata:auth_campusconnect', $table->get_summary());
        foreach ($privacyfields as $field) {
            get_string($field, 'auth_campusconnect');
        }
        get_string($table->get_summary(), 'auth_campusconnect');
    }

    /**
     * Test for provider::get_contexts_for_userid().
     */
    public function test_get_contexts_for_userid() {
        $contextlist = provider::get_contexts_for_userid($this->userwithrecord->id);
        $this->assertEquals([context_system::instance()->id], $contextlist->get_contextids());

        $contextlist = provider::get_contexts_for_userid($this->userwithoutrecord->id);
        $this->assertEmpty($contextlist);
    }

    /**
     * Test for provider::export_user_data().
     */
    public function test_export_for_context() {
        $ctx = context_system::instance();

        // Export all of the data for the context.
        $this->export_context_data_for_user($this->userwithrecord->id, $ctx, 'auth_campusconnect');
        $writer = \core_privacy\local\request\writer::with_context($ctx);
        $this->assertTrue($writer->has_any_data());

        \core_privacy\local\request\writer::reset();
        $this->export_context_data_for_user($this->userwithoutrecord->id, $ctx, 'auth_campusconnect');
        $writer = \core_privacy\local\request\writer::with_context($ctx);
        $this->assertFalse($writer->has_any_data());
    }

    /**
     * Test for provider::delete_data_for_all_users_in_context().
     */
    public function test_delete_data_for_all_users_in_context() {
        global $DB;

        provider::delete_data_for_all_users_in_context(context_system::instance());
        $this->assertFalse($DB->record_exists('auth_campusconnect', []));
    }

    /**
     * Test for provider::delete_data_for_user().
     */
    public function test_delete_data_for_user() {
        global $DB;

        $ctx = context_system::instance();
        $contextlist = new \core_privacy\local\request\approved_contextlist($this->userwithrecord, 'auth_campusconnect',
                                                                            [$ctx->id]);
        provider::delete_data_for_user($contextlist);

        $recs = $DB->get_records('auth_campusconnect', []);
        $this->assertCount(1, $recs);
        list($rec) = array_values($recs);
        $this->assertEquals($this->userwithrecord2->username, $rec->username);
    }

    public function test_get_users_in_context() {
        $ctx = context_system::instance();

        $userlist = new \core_privacy\local\request\userlist($ctx, 'auth_campusconnect');
        provider::get_users_in_context($userlist);
        $this->assertCount(2, $userlist);

        $this->assertEquals([$this->userwithrecord->id, $this->userwithrecord2->id], $userlist->get_userids(), '', 0.0, 10, true);
    }

    public function test_delete_data_for_users() {
        $ctx = context_system::instance();

        $approvedlist = new \core_privacy\local\request\approved_userlist($ctx, 'auth_campusconnect', [$this->userwithrecord->id]);
        provider::delete_data_for_users($approvedlist);

        $userlist = new \core_privacy\local\request\userlist($ctx, 'auth_campusconnect');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->userwithrecord2->id], $userlist->get_userids());
    }
}
