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

use local_campusconnect\participantsettings;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/local/campusconnect/tests/testbase.php');

class local_campusconnect_participantsettings_test extends campusconnect_base_testcase {

    public function test_load_participants() {
        $communities = participantsettings::load_communities($this->connect[1]->get_settings());
        $this->assertTrue(is_array($communities), 'Expected an array of communities');
        $this->assertCount(1, $communities, 'Expected unittest1 to be part of just one community');
        $community = reset($communities);
        $this->assertEquals('unittest', $community->name, "Expected the community to be called 'unittest'");

        /** @var $parts participantsettings[] */
        $parts = $community->participants;
        $this->assertCount(3, $parts, "Expected 3 participants in the 'unittest' community");

        $expectednames = array('Unit test 1', 'Unit test 2', 'Unit test 3');
        $expecteddisplaynames = array('unittest: Unit test 1', 'unittest: Unit test 2', 'unittest: Unit test 3');
        foreach ($parts as $part) {
            $this->assertInstanceOf('\local_campusconnect\participantsettings', $part);
            $name = $part->get_name();
            $pos = array_search($name, $expectednames);
            $this->assertInternalType('integer', $pos, "Unexpected participant '$name'");
            unset($expectednames[$pos]);

            $displayname = $part->get_displayname();
            $pos = array_search($displayname, $expecteddisplaynames);
            $this->assertInternalType('integer', $pos);
        }
    }

    public function test_save_settings() {
        // Get the first participant in the community.
        $communities = participantsettings::load_communities($this->connect[1]->get_settings());
        $community = reset($communities);
        $participant = reset($community->participants);
        $mid = $participant->get_mid();

        // Check the default settings.
        $this->assertFalse($participant->is_export_enabled(), 'Participants should default to not receiving exported courses');
        $this->assertFalse($participant->is_import_enabled(), 'Participants should default to not having courses imported');
        $this->assertEquals(participantsettings::IMPORT_LINK, $participant->get_import_type(),
                            'Participants should default to importtype IMPORT_LINK');

        // Change all the settings.
        $settings = array('import' => true, 'export' => true, 'importtype' => participantsettings::IMPORT_COURSE);
        $participant->save_settings($settings);

        // Check all settings have updated immediately.
        $this->assertTrue($participant->is_export_enabled(), 'Export setting not updated');
        $this->assertTrue($participant->is_import_enabled(), 'Import setting not updated');
        $this->assertEquals(participantsettings::IMPORT_COURSE, $participant->get_import_type(), 'Importtype setting not updated');

        $settings = $participant->get_settings();
        $this->assertEquals($participant->is_export_enabled(), $settings->export, 'Export setting internal consistency failure');
        $this->assertEquals($participant->is_import_enabled(), $settings->import, 'Import setting internal consistency failure');
        $this->assertEquals($participant->get_import_type(), $settings->importtype,
                            'Importtype setting internal consistency failure');
        $this->assertEquals($participant->get_name(), $settings->name, 'name setting internal consistency failure');

        // Check settings all save correctly.
        $participant = new participantsettings($this->connect[1]->get_settings()->get_id(), $mid);
        $this->assertTrue($participant->is_export_enabled(), 'Export setting not saved');
        $this->assertTrue($participant->is_import_enabled(), 'Import setting not saved');
        $this->assertEquals(participantsettings::IMPORT_COURSE, $participant->get_import_type(), 'Importtype setting not saved');
    }

    public function test_settings_validation() {
        // Get the first participant in the community.
        $communities = participantsettings::load_communities($this->connect[1]->get_settings());
        $community = reset($communities);
        /** @var $participant participantsettings */
        $participant = reset($community->participants);

        $settings = array('import' => 'fish', 'export' => 500);
        $participant->save_settings($settings);
        $this->assertTrue($participant->is_import_enabled());
        $this->assertTrue($participant->is_export_enabled());

        $settings['import'] = 0;
        $settings['export'] = '';
        $participant->save_settings($settings);
        $this->assertFalse($participant->is_import_enabled());
        $this->assertFalse($participant->is_export_enabled());

        // Check these settings can be set without an exception.
        $settings['importtype'] = participantsettings::IMPORT_LINK;
        $participant->save_settings($settings);
        $settings['importtype'] = participantsettings::IMPORT_COURSE;
        $participant->save_settings($settings);
        $settings['importtype'] = participantsettings::IMPORT_CMS;
        $participant->save_settings($settings);
        // Check validation of invalid settings.
        $settings['importtype'] = 500;
        try {
            $participant->save_settings($settings);
            $this->fail('Expected coding_exception');
        } catch (coding_exception $e) {
            // Expected exception.
        }
    }
}
