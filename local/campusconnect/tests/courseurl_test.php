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
 * Tests for the course URL sending for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2014 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\connect;
use local_campusconnect\course_url;
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
 * Class local_campusconnect_course_url_test
 * @group local_campusconnect
 */
class local_campusconnect_courseurl_test extends advanced_testcase {
    /**
     * @var connect[]
     */
    protected $connect = array();
    /**
     * @var integer[]
     */
    protected $mid = array();

    protected function setUp() {
        global $DB;

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
            $memberships = $connect->get_memberships();
            foreach ($memberships[0]->participants as $participant) {
                if ($participant->itsyou) {
                    $this->mid[$key] = $participant->mid;
                    break;
                }
            }
        }

        // Set 'unittest2' as the CMS for 'unittest1'.
        $part = (object)array(
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2],
            'export' => 0,
            'import' => 1,
            'importtype' => participantsettings::IMPORT_CMS,
        );
        $DB->insert_record('local_campusconnect_part', $part);
        participantsettings::get_cms_participant(true); // Reset the cached 'cms participant' value.

        // Delete all course urls created by 'unittest1' (in case there are some left over from a failed test).
        $courselinks = $this->connect[1]->get_resource_list(event::RES_COURSE_URL, connect::SENT);
        foreach ($courselinks->get_ids() as $eid) {
            $this->connect[1]->delete_resource($eid, event::RES_COURSE_URL);
        }
    }

    protected function tearDown() {
        // Delete all course urls created by 'unittest1'.
        $courselinks = $this->connect[1]->get_resource_list(event::RES_COURSE_URL, connect::SENT);
        foreach ($courselinks->get_ids() as $eid) {
            $this->connect[1]->delete_resource($eid, event::RES_COURSE_URL);
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

    public function test_create_course_url() {
        global $DB;

        // Create crs record.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add it to queue.
        $courseurl = new course_url($crsid);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Check resource recieved (by 'unittest2' from 'unittest1').
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(1, $ids);
        $courseurlid = reset($ids);
        $courseurlres = $this->connect[2]->get_resource($courseurlid, event::RES_COURSE_URL);

        $this->assertEquals($ins->cmsid, $courseurlres->cms_course_id);
        $this->assertCount(1, $courseurlres->lms_course_urls); // No parallel groups => only 1 URL expected.
        $expectedurl = new moodle_url('/course/view.php', array('id' => $ins->courseid));
        $actualurl = reset($courseurlres->lms_course_urls);
        $this->assertEquals($expectedurl->out(), $actualurl->url);
    }

    public function test_update_course_url() {
        global $DB;

        // Create crs record.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add it to queue.
        $courseurl = new course_url($crsid);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Check resource recieved (by 'unittest2' from 'unittest1').
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(1, $ids);
        $courseurlid = reset($ids);
        $courseurlres = $this->connect[2]->get_resource($courseurlid, event::RES_COURSE_URL);

        $this->assertEquals($ins->cmsid, $courseurlres->cms_course_id);

        // Update the course record.
        $upd = (object)array(
            'id' => $crsid,
            'cmsid' => 'updated456',
        );
        $DB->update_record('local_campusconnect_crs', $upd);
        $courseurl = new course_url($crsid);
        $courseurl->update();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Check resource recieved (by 'unittest2' from 'unittest1').
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(1, $ids);
        $courseurlid = reset($ids);
        $courseurlres = $this->connect[2]->get_resource($courseurlid, event::RES_COURSE_URL);

        $this->assertEquals($upd->cmsid, $courseurlres->cms_course_id);
        $this->assertCount(1, $courseurlres->lms_course_urls);
    }

    public function test_delete_course_url() {
        global $DB;

        // Create crs record.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add it to queue.
        $courseurl = new course_url($crsid);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Delete the course url.
        $courseurl = new course_url($crsid);
        $courseurl->delete();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Check no resources recieved (by 'unittest2' from 'unittest1').
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $this->assertCount(0, $courseurls->get_ids());
    }

    public function test_refresh_ecs() {
        global $DB;

        $resids = array();

        // Create crs record.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add it to queue.
        $courseurl = new course_url($crsid);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);
        $resids[1] = $DB->get_field('local_campusconnect_crs', 'urlresourceid', array('id' => $crsid), MUST_EXIST);

        // Insert an extra, unwanted URL resource on the server.
        $cms = participantsettings::get_cms_participant();
        $fakecourseurl = (object)array(
            'cms_course_id' => 'unwantedurl',
            'ecs_course_url' => $this->connect[1]->get_resource_url('40', event::RES_COURSE), // Fake resource id.
            'lms_course_url' => array((object)array('title' => 'fakeurl', 'url' => 'fakeurl')),
        );
        $resids[2] = $this->connect[1]->add_resource(event::RES_COURSE_URL, $fakecourseurl, null, $cms->get_mid());

        // Add an extra crs record, that is not synced with server.
        $ins2 = (object)array(
            'courseid' => '130', // Made-up courseid.
            'resourceid' => '56', // Made-up resourceid.
            'cmsid' => 'anothertest456',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => '44', // Made-up resourceid - should not be found on the server.
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid2 = $DB->insert_record('local_campusconnect_crs', $ins2);

        // Change the 'cmsid' for the existing exported URL.
        $upd = (object)array(
            'id' => $crsid,
            'cmsid' => 'updated456',
        );
        $DB->update_record('local_campusconnect_crs', $upd);

        // Check the current status on the ECS server, this should show:
        // 1. The expected, existing course URL.
        // 2. The unwanted course URL.
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(2, $ids);
        $this->assertContains($resids[1], $ids);
        $this->assertContains($resids[2], $ids);

        $res1 = $this->connect[2]->get_resource($resids[1], event::RES_COURSE_URL);
        $this->assertEquals($ins->cmsid, $res1->cms_course_id);
        $res2 = $this->connect[2]->get_resource($resids[2], event::RES_COURSE_URL);
        $this->assertEquals($fakecourseurl->cms_course_id, $res2->cms_course_id);

        // Refresh the ECS, this should:
        // 1. Remove the unwanted course URL.
        // 2. Update the existing course URL.
        // 3. Add the course URL that was not found on the ECS server.
        $result = course_url::refresh_ecs($this->connect[1]);

        $this->assertCount(1, $result->created);
        $this->assertCount(1, $result->updated);
        $this->assertCount(1, $result->deleted);

        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(2, $ids);
        $this->assertContains($resids[1], $ids);
        $this->assertNotContains($resids[2], $ids); // Unwanted course url should no longer be present.

        foreach ($ids as $resid) {
            if ($resid != $resids[1]) {
                $resids[3] = $resid; // Find the new resourceid, that has just been created.
            }
        }
        $this->assertArrayHasKey(3, $resids); // Make sure we found the new resourceid.

        $res1 = $this->connect[2]->get_resource($resids[1], event::RES_COURSE_URL);
        $this->assertEquals($upd->cmsid, $res1->cms_course_id); // Make sure the cms_course_id was updated.
        $this->assertCount(1, $res1->lms_course_urls);

        $res2 = $this->connect[2]->get_resource($resids[3], event::RES_COURSE_URL);
        $this->assertEquals($ins2->cmsid, $res2->cms_course_id); // Make sure the new resource was inserted correctly.
        $this->assertCount(1, $res2->lms_course_urls);
    }

    public function test_multiple_create_course_url() {
        global $DB;

        // This test creates 3 crs records - 2 pointing to parallel groups, 1 pointing to an internal link course.
        // Expect a single exported course url record, which links to the two parallel groups, but not to the internal link.

        // Create crs records.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid1 = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add a paralell group record (note this is a separate course, not an internal link back to the first course,
        // but both were created from the same resource).
        $ins2 = clone $ins;
        $ins2->courseid = '111'; // Made-up courseid (but different from the first group).
        $crsid2 = $DB->insert_record('local_campusconnect_crs', $ins2);

        // Add an internal link - this should not have a course url exported for it.
        $ins3 = clone $ins;
        $ins3->courseid = '112';
        $ins3->internallink = '110'; // Link back to course 110.
        $crsid3 = $DB->insert_record('local_campusconnect_crs', $ins3);

        // Add it to queue (should only need to mark 1 course as updated, all others should be picked up).
        $courseurl = new course_url($crsid1);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);

        // Check resource recieved (by 'unittest2' from 'unittest1').
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(1, $ids); // Still expect only 1 URL (but with both 'real' courses in it).
        $courseurlid = reset($ids);
        $courseurlres = $this->connect[2]->get_resource($courseurlid, event::RES_COURSE_URL);

        $this->assertEquals($ins->cmsid, $courseurlres->cms_course_id);
        $this->assertCount(2, $courseurlres->lms_course_urls); // 2 parallel groups => 2 course urls exptected (but not the internal link).

        $expectedurl1 = new moodle_url('/course/view.php', array('id' => $ins->courseid));
        $expectedurl2 = new moodle_url('/course/view.php', array('id' => $ins2->courseid));
        $actualurls = array();
        foreach ($courseurlres->lms_course_urls as $lmsurl) {
            $actualurls[] = $lmsurl->url;
        }
        $this->assertContains($expectedurl1->out(), $actualurls);
        $this->assertContains($expectedurl2->out(), $actualurls);

        // Check the urlresourceids have been correctly saved in the DB.
        $crs1 = $DB->get_record('local_campusconnect_crs', array('id' => $crsid1));
        $crs2 = $DB->get_record('local_campusconnect_crs', array('id' => $crsid2));
        $crs3 = $DB->get_record('local_campusconnect_crs', array('id' => $crsid3));

        $this->assertEquals($courseurlid, $crs1->urlresourceid);
        $this->assertEquals($courseurlid, $crs2->urlresourceid);
        $this->assertEquals(0, $crs3->urlresourceid); // Internal link - should not have an associated url resource.
    }

    public function test_multiple_refresh_ecs() {
        global $DB;

        $resids = array();

        // This test creates 3 crs records - 2 pointing to parallel groups, 1 pointing to an internal link course.
        // Expect a single exported course url record, which links to the two parallel groups, but not to the internal link.
        // It then adds an unwanted record on the ECS only, along with an extra record on the local system, missing from the ECS.
        // Expect the ECS to match the local records, after the update.

        // Create crs record.
        $ins = (object)array(
            'courseid' => '110', // Made-up courseid.
            'resourceid' => '25', // Made-up resourceid.
            'cmsid' => 'testing123',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => 0,
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid = $DB->insert_record('local_campusconnect_crs', $ins);

        // Add a paralell group record (note this is a separate course, not an internal link back to the first course,
        // but both were created from the same resource).
        $ins2 = clone $ins;
        $ins2->courseid = '111'; // Made-up courseid (but different from the first group).
        $crsid2 = $DB->insert_record('local_campusconnect_crs', $ins2);

        // Add an internal link - this should not have a course url exported for it.
        $ins3 = clone $ins;
        $ins3->courseid = '112';
        $ins3->internallink = '110'; // Link back to course 110.
        $crsid3 = $DB->insert_record('local_campusconnect_crs', $ins3);

        // Add it to queue.
        $courseurl = new course_url($crsid);
        $courseurl->add();

        // Update ECS (from 'unittest1' to CMS 'unittest2').
        course_url::update_ecs($this->connect[1]);
        $resids[1] = $DB->get_field('local_campusconnect_crs', 'urlresourceid', array('id' => $crsid), MUST_EXIST);

        // Insert an extra, unwanted URL resource on the server.
        $cms = participantsettings::get_cms_participant();
        $fakecourseurl = (object)array(
            'cms_course_id' => 'unwantedurl',
            'ecs_course_url' => $this->connect[1]->get_resource_url('40', event::RES_COURSE), // Fake resource id.
            'lms_course_url' => array((object)array('title' => 'fakeurl', 'url' => 'fakeurl')),
        );
        $resids[2] = $this->connect[1]->add_resource(event::RES_COURSE_URL, $fakecourseurl, null, $cms->get_mid());

        // Add an extra crs record, that is not synced with server.
        $ins4 = (object)array(
            'courseid' => '130', // Made-up courseid.
            'resourceid' => '56', // Made-up resourceid.
            'cmsid' => 'anothertest456',
            'ecsid' => $this->connect[1]->get_ecs_id(),
            'mid' => $this->mid[2], // 'unittest2'.
            'internallink' => 0,
            'urlresourceid' => '44', // Made-up resourceid - should not be found on the server.
            'urlstatus' => course_url::STATUS_UPTODATE, // Initial status.
            'sortorder' => 0,
            'directoryid' => 0,
        );
        $crsid4 = $DB->insert_record('local_campusconnect_crs', $ins4);

        // Change the 'cmsid' for the existing exported URL.
        $upd = (object)array(
            'id' => $crsid,
            'cmsid' => 'updated456',
        );
        $DB->update_record('local_campusconnect_crs', $upd);

        // Check the current status on the ECS server, this should show:
        // 1. The expected, existing course URL.
        // 2. The unwanted course URL.
        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(2, $ids);
        $this->assertContains($resids[1], $ids);
        $this->assertContains($resids[2], $ids);

        $res1 = $this->connect[2]->get_resource($resids[1], event::RES_COURSE_URL);
        $this->assertEquals($ins->cmsid, $res1->cms_course_id);
        $res2 = $this->connect[2]->get_resource($resids[2], event::RES_COURSE_URL);
        $this->assertEquals($fakecourseurl->cms_course_id, $res2->cms_course_id);

        // Refresh the ECS, this should:
        // 1. Remove the unwanted course URL.
        // 2. Update the existing course URL.
        // 3. Add the course URL that was not found on the ECS server.
        $result = course_url::refresh_ecs($this->connect[1]);

        $this->assertCount(1, $result->created);
        $this->assertCount(1, $result->updated);
        $this->assertCount(1, $result->deleted);

        $courseurls = $this->connect[2]->get_resource_list(event::RES_COURSE_URL);
        $ids = $courseurls->get_ids();
        $this->assertCount(2, $ids);
        $this->assertContains($resids[1], $ids);
        $this->assertNotContains($resids[2], $ids); // Unwanted course url should no longer be present.

        foreach ($ids as $resid) {
            if ($resid != $resids[1]) {
                $resids[3] = $resid; // Find the new resourceid, that has just been created.
            }
        }
        $this->assertArrayHasKey(3, $resids); // Make sure we found the new resourceid.

        $res1 = $this->connect[2]->get_resource($resids[1], event::RES_COURSE_URL);
        $this->assertEquals($upd->cmsid, $res1->cms_course_id); // Make sure the cms_course_id was updated.
        $this->assertCount(2, $res1->lms_course_urls); // Make sure there are the two 'real' course URLs.
        $expectedurl1 = new moodle_url('/course/view.php', array('id' => $ins->courseid));
        $expectedurl2 = new moodle_url('/course/view.php', array('id' => $ins2->courseid));
        $actualurls = array();
        foreach ($res1->lms_course_urls as $lmsurl) {
            $actualurls[] = $lmsurl->url;
        }
        $this->assertContains($expectedurl1->out(), $actualurls);
        $this->assertContains($expectedurl2->out(), $actualurls);

        $res2 = $this->connect[2]->get_resource($resids[3], event::RES_COURSE_URL);
        $this->assertEquals($ins4->cmsid, $res2->cms_course_id); // Make sure the new resource was inserted correctly.
        $this->assertCount(1, $res2->lms_course_urls);
        $expectedurl = new moodle_url('/course/view.php', array('id' => $ins4->courseid));
        $actualurl = reset($res2->lms_course_urls);
        $this->assertEquals($expectedurl->out(), $actualurl->url);
    }
}
