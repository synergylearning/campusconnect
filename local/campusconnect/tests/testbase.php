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
 * Base class for CampusConnect tests - sets up standard ECS connections
 *
 * @package   local_campusconnect
 * @copyright 2015 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\connect;
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

class campusconnect_base_testcase extends advanced_testcase {
    /** @var connect[] */
    protected $connect = array();
    protected $mid = array();
    protected $community = 'unittest';

    public function setUp() {
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
                }
            }
        }

        // Set participant 1 as the CMS for participant 2.
        $part = (object)array(
            'ecsid' => $this->connect[2]->get_ecs_id(),
            'mid' => $this->mid[1],
            'export' => 0,
            'import' => 1,
            'importtype' => participantsettings::IMPORT_CMS,
        );
        $DB->insert_record('local_campusconnect_part', $part);
        participantsettings::get_cms_participant(true); // Reset the cached 'cms participant' value.
    }

    protected function tearDown() {
        $this->clear_ecs_resources(event::RES_DIRECTORYTREE);

        $this->connect = array();
        $this->mid = array();
    }

    protected function clear_ecs_resources($type) {
        foreach ($this->connect as $connect) {
            // Delete all resources sent by each connection.
            $resouces = $connect->get_resource_list($type, connect::SENT);
            foreach ($resouces->get_ids() as $eid) {
                $this->connect[1]->delete_resource($eid, $type);
            }
        }
        foreach ($this->connect as $connect) {
            // Delete all events.
            while ($connect->read_event_fifo(true)) {
                ;
            }
        }
    }
}