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
 * Test course link creation + authentication
 *
 * @package    local_campusconnect
 * @copyright  2014 Davo Smith, Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\connect;
use local_campusconnect\courselink;
use local_campusconnect\ecssettings;
use local_campusconnect\event;
use local_campusconnect\export;
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
 * Class local_campusconnect_courselink_test
 * @group local_campusconnect
 */
class local_campusconnect_courselink_test extends advanced_testcase {
    /**
     * @var connect[]
     */
    protected $connect = array();
    /**
     * @var integer[]
     */
    protected $mid = array();

    protected function setUp() {
        global $CFG;

        require_once($CFG->dirroot.'/auth/campusconnect/auth.php');

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
            // Make sure all data structures are initialised.
            participantsettings::load_communities($this->connect[1]->get_settings());

            $memberships = $connect->get_memberships();
            foreach ($memberships[0]->participants as $participant) {
                if ($participant->itsyou) {
                    $this->mid[$key] = $participant->mid;
                    break;
                }
            }
        }

        participantsettings::reset_custom_fields();
    }

    protected function tearDown() {
        // Delete all resources (just in case).
        foreach ($this->connect as $connect) {
            $courselinks = $connect->get_resource_list(event::RES_COURSELINK);
            foreach ($courselinks->get_ids() as $eid) {
                // All courselinks were created by 'unittest1'.
                $this->connect[1]->delete_resource($eid, event::RES_COURSELINK);
            }
        }

        // Delete all events.
        foreach ($this->connect as $connect) {
            while ($connect->read_event_fifo(true)) {
                ;
            }
        }

        $this->connect = array();
        $this->mid = array();
    }

    protected function setup_courselink() {
        global $DB;

        // Course link from 'unittest1' => 'unittest2'.
        $part1 = new participantsettings($this->connect[1]->get_ecs_id(), $this->mid[2]);
        $part1->save_settings(array('export' => true));
        $part2 = new participantsettings($this->connect[2]->get_ecs_id(), $this->mid[1]);
        $part2->save_settings(array('import' => true, 'importtype' => participantsettings::IMPORT_LINK));

        // Check there are currently no course links on 'unittest2'.
        $courselinks = $DB->get_records('local_campusconnect_clink', array(
            'ecsid' => $this->connect[2]->get_ecs_id(),
            'mid' => $this->mid[1]
        ));
        $this->assertEmpty($courselinks);

        // Generate a course + export it to 'unittest2'.
        $srccourse = $this->getDataGenerator()->create_course(
            array('fullname' => 'test full name', 'shortname' => 'test short name')
        );
        $export = new export($srccourse->id);
        $export->set_export($part1->get_identifier(), true);

        // Run the updates.
        export::update_ecs($this->connect[1]); // Export.
        courselink::refresh_from_participant($this->connect[2]->get_ecs_id(), $this->mid[1]); // Import.

        // Retrieve the courselinks on 'unittest2'.
        $courselinks = $DB->get_records('local_campusconnect_clink', array(
            'ecsid' => $this->connect[2]->get_ecs_id(),
            'mid' => $this->mid[1]
        ));
        $this->assertCount(1, $courselinks); // Should only have imported 1 course link.
        $courselink = reset($courselinks);
        $dstcourseid = $courselink->courseid; // The course that represents the course link on 'unittest1'.
        $course = $DB->get_record('course', array('id' => $dstcourseid));
        $this->assertEquals('test full name', $course->fullname); // Make sure the correct course link has been created.
        // Cannot test the shortname, as that will have been renamed for conflicting with the original course shortname.

        return array($dstcourseid, $part1, $part2);
    }

    public function test_legacy_courselink_authentication() {
        list($dstcourseid, $part1, $part2) = $this->setup_courselink();
        /** @var participantsettings $part2 */
        $part2->save_settings(array('uselegacy' => true));

        // Generate a URL on 'unittest2'.
        $authuser = $this->getDataGenerator()->create_user(
            array(
                'firstname' => 'firstname1',
                'lastname' => 'lastname1',
                'email' => 'testuser1@example.com',
                'username' => 'firstname1.lastname1'
            )
        );
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.

        // Authenticate the URL on 'unittest1'.
        $userdetails = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails); // Check the user has authenticated correctly.
        foreach (array('firstname', 'lastname', 'email') as $fieldname) { // Make sure all user details transferred as expected.
            $this->assertEquals($authuser->$fieldname, $userdetails->$fieldname);
        }

        // Generate a second URL on 'unittest2'.
        $authuser->firstname = 'firstname2';
        $authuser->lastname = 'lastname2';
        $authuser->email = 'testuser2@example.com';
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.

        // Authenticate this URL and check that the same username is retrieved and the details have been updated correctly.
        $userdetails2 = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails2);
        $this->assertEquals($userdetails->username, $userdetails2->username); // Should be matched up to the same username.
        foreach (array('firstname', 'lastname', 'email') as $fieldname) { // Make sure all user details updated.
            $this->assertEquals($authuser->$fieldname, $userdetails2->$fieldname);
        }
    }

    public function test_token_settings() {
        $authuser = $this->getDataGenerator()->create_user(
            array(
                'firstname' => 'firstname1',
                'lastname' => 'lastname1',
                'email' => 'testuser1@example.com',
                'username' => 'firstname1.lastname1'
            )
        );
        list($dstcourseid, $part1, $part2) = $this->setup_courselink();

        // Make sure the token data is ignored when disabled on the receiving site.
        /** @var participantsettings $part1 */
        $part1->save_settings(array('exporttoken' => false)); // Disable handling of token for exported courselinks.
        $url = courselink::check_redirect($dstcourseid, $authuser);

        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertArrayHasKey('ecs_hash', $params); // Make sure the 'ecs_hash' has been added.

        $userdetails = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNull($userdetails); // Check that the authentication is ignored.

        // Make sure the token data is not generated when disabled on the sending site.
        /** @var participantsettings $part2 */
        $part2->save_settings(array('importtoken' => false)); // Disable sending of token for imported courselinks.
        $url = courselink::check_redirect($dstcourseid, $authuser);

        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertCount(1, $params);
        $this->assertArrayHasKey('id', $params); // Check the URL only has the courseid and no other details have been added.
    }

    public function test_courselink_authentication_default_mappings() {
        list($dstcourseid, $part1, $part2) = $this->setup_courselink();
        /** @var participantsettings $part2 */

        // Generate a URL on 'unittest2'.
        $authuser = $this->getDataGenerator()->create_user(
            array(
                'firstname' => 'firstname1',
                'lastname' => 'lastname1',
                'email' => 'testuser1@example.com',
                'username' => 'firstname1.lastname1'
            )
        );
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.
        $this->assertRegExp('|ecs_person_id_type=ecs_uid|', $url); // Make sure the 'ecs_person_id_type' param is present.
        $uid = participantsettings::get_uid_prefix().$authuser->id;
        $this->assertRegExp('|ecs_uid='.preg_quote($uid).'|', $url);
        $this->assertRegExp('|ecs_firstname=firstname1|', $url);
        $this->assertRegExp('|ecs_lastname=lastname1|', $url);
        $this->assertRegExp('|ecs_email='.urlencode('testuser1@example.com').'|', $url);
        $this->assertRegExp('|ecs_login=firstname1.lastname1|', $url);
        $this->assertNotRegExp('|ecs_eppn=|', $url); // Should not be in the default export.
        $this->assertNotRegExp('|ecs_PersonalUniqueCode=|', $url);

        // Authenticate the URL on 'unittest1'.
        $userdetails = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails); // Check the user has authenticated correctly.
        foreach (array('firstname', 'lastname', 'email') as $fieldname) { // Make sure all user details transferred as expected.
            $this->assertEquals($authuser->$fieldname, $userdetails->$fieldname);
        }

        // Generate a second URL on 'unittest2'.
        $authuser->firstname = 'firstname2';
        $authuser->lastname = 'lastname2';
        $authuser->email = 'testuser2@example.com';
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.

        // Authenticate this URL and check that the same username is retrieved and the details have been updated correctly.
        $userdetails2 = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails2);
        $this->assertEquals($userdetails->username, $userdetails2->username); // Should be matched up to the same username.
        foreach (array('firstname', 'lastname', 'email') as $fieldname) { // Make sure all user details updated.
            $this->assertEquals($authuser->$fieldname, $userdetails2->$fieldname);
        }
    }

    protected function create_text_profile_field($fieldname) {
        global $CFG;
        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/profile/definelib.php');
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

    public function test_courselink_authentication_custom_mappings() {
        list($dstcourseid, $part1, $part2) = $this->setup_courselink();

        // Define some custom user profile fields.
        $this->create_text_profile_field('eppn');
        $this->create_text_profile_field('eppn2');
        participantsettings::reset_custom_fields();

        // Override the default export mappings.
        /** @var participantsettings $part2 */
        $mapping = $part2->get_export_mappings();
        $mapping[courselink::PERSON_EPPN] = 'custom_eppn';
        $mapping[courselink::PERSON_UNIQUECODE] = 'department';
        $exportfields = $part2->get_export_fields();
        $exportfields[] = courselink::PERSON_EPPN;
        $exportfields[] = courselink::PERSON_UNIQUECODE;
        $part2->save_settings(array('exportfieldmapping' => $mapping, 'exportfields' => $exportfields));

        // Override the default import mappings.
        /** @var participantsettings $part1 */
        $mapping = $part1->get_import_mappings();
        $mapping[courselink::PERSON_EPPN] = 'custom_eppn2';
        $mapping[courselink::PERSON_UNIQUECODE] = 'address';
        $mapping[courselink::PERSON_EMAIL] = 'idnumber';
        $part1->save_settings(array('importfieldmapping' => $mapping));

        // Generate a URL on 'unittest2'.
        $authuser = $this->getDataGenerator()->create_user(
            array(
                'firstname' => 'firstname1',
                'lastname' => 'lastname1',
                'email' => 'testuser1@example.com',
                'username' => 'firstname1.lastname1',
                'department' => 'department1'
            ) // Mapped on to 'ecs_PersonalUniqueCode.
        );
        $this->set_profile_field($authuser, 'eppn', 'myeppn');
        $authuser = get_complete_user_data('id', $authuser->id);

        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.
        $this->assertRegExp('|ecs_person_id_type=ecs_uid|', $url); // Make sure the 'ecs_person_id_type' param is present.
        $uid = participantsettings::get_uid_prefix().$authuser->id;
        $this->assertRegExp('|ecs_uid='.preg_quote($uid).'|', $url);
        $this->assertRegExp('|ecs_firstname=firstname1|', $url);
        $this->assertRegExp('|ecs_lastname=lastname1|', $url);
        $this->assertRegExp('|ecs_email='.urlencode('testuser1@example.com').'|', $url);
        $this->assertRegExp('|ecs_login=firstname1.lastname1|', $url);
        $this->assertRegExp('|ecs_eppn=myeppn|', $url);
        $this->assertRegExp('|ecs_PersonalUniqueCode=department1|', $url);

        // Authenticate the URL on 'unittest1'.
        $userdetails = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails); // Check the user has authenticated correctly.
        $this->assertEquals('firstname1', $userdetails->firstname);
        $this->assertEquals('lastname1', $userdetails->lastname);
        $this->assertEquals('testuser1@example.com', $userdetails->idnumber); // email => PERSON_EMAIL => idnumber.
        $this->assertEquals('department1', $userdetails->address); // department => PERSON_UNIQUECODE => address.
        $this->assertEquals('myeppn', $userdetails->custom_eppn2); // custom_eppn => PERSON_EPPN => custom_eppn2.

        // Generate a second URL on 'unittest2'.
        $this->set_profile_field($authuser, 'eppn', 'myeppn2');
        $authuser = get_complete_user_data('id', $authuser->id); // Reload with new profile field.
        $authuser->firstname = 'firstname2';
        $authuser->lastname = 'lastname2';
        $authuser->email = 'testuser2@example.com';
        $authuser->department = 'department2';
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.

        // Authenticate this URL and check that the same username is retrieved and the details have been updated correctly.
        $userdetails2 = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails2);
        $this->assertEquals($userdetails->username, $userdetails2->username); // Should be matched up to the same username.
        $this->assertEquals('firstname2', $userdetails2->firstname);
        $this->assertEquals('lastname2', $userdetails2->lastname);
        $this->assertEquals('testuser2@example.com', $userdetails2->idnumber); // email => PERSON_EMAIL => idnumber.
        $this->assertEquals('department2', $userdetails2->address); // department => PERSON_UNIQUECODE => address.
        $this->assertEquals('myeppn2', $userdetails2->custom_eppn2); // custom_eppn => PERSON_EPPN => custom_eppn2.
    }

    public function test_courselink_authentication_existing_user() {
        /** @var participantsettings $part1 */
        /** @var participantsettings $part2 */
        list($dstcourseid, $part1, $part2) = $this->setup_courselink();
        $exportfields = $part2->get_export_fields();
        $exportfields[] = courselink::PERSON_LOGINUID;
        $exportmappings = $part2->get_export_mappings();
        $exportmappings[courselink::PERSON_LOGINUID] = 'idnumber';
        $personuidtype = courselink::PERSON_LOGINUID;
        $part2->save_settings(array(
                                  'exportfields' => $exportfields,
                                  'exportfieldmapping' => $exportmappings,
                                  'personuidtype' => $personuidtype
                              ));

        $importfields = $part1->get_import_mappings();
        $importfields[courselink::PERSON_LOGINUID] = 'idnumber';
        $part1->save_settings(array('importfieldmapping' => $importfields));

        // Generate a URL on 'unittest2'.
        $authuser = $this->getDataGenerator()->create_user(
            array(
                'firstname' => 'firstname1',
                'lastname' => 'lastname1',
                'email' => 'testuser1@example.com',
                'username' => 'firstname1.lastname1',
                'idnumber' => 'myloginuid1'
            ) // Mapped on to PERSON_LOGINUID in export / import.
        );
        $url = courselink::check_redirect($dstcourseid, $authuser);
        $this->assertNotEquals(false, $url); // Make sure this is correctly identified as a course link.

        // Authenticate the URL on 'unittest1'.
        $userdetails = auth_plugin_campusconnect::authenticate_from_url($url);
        $this->assertNotNull($userdetails); // Check the user has authenticated correctly.
        $this->assertEquals('firstname1.lastname1', $userdetails->username); // Check they are matched up to the existing user.
    }
}
