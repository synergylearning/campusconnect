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
 * Tests for course import filtering for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2014 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\ecssettings;
use local_campusconnect\filtering;
use local_campusconnect\metadata;

defined('MOODLE_INTERNAL') || die();

/**
 * Class local_campusconnect_coursemembers_test
 * @group local_campusconnect
 */
class local_campusconnect_filtering_test extends advanced_testcase {

    public function setUp() {
        $this->resetAfterTest();
    }

    public function test_check_filter_match() {

        // Test a single 'allwords' filter match.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => false
            )
        );
        $metadata = array(
            'attribute1' => 'testvalue',
            'attribute2' => 'fish'
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Test matching multiple 'allwords' filters.
        $filter['attribute2'] = (object)array(
            'allwords' => true,
            'words' => array(),
            'createsubdirectories' => false
        );
        $filter['attribute3'] = (object)array(
            'allwords' => true,
            'words' => array(),
            'createsubdirectories' => false
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Test matching a single 'specific words' filter.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => false,
                'words' => array('cat', 'testvalue', 'dog'),
                'createsubdirectories' => false
            )
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Test matching multiple 'specific words' filters.
        $filter['attribute2'] = (object)array(
            'allwords' => false,
            'words' => array('cow', 'horse', 'fish'),
            'createsubdirectories' => false
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Test failing due to missing attribute in metadata.
        $filter['attribute3'] = (object)array(
            'allwords' => false,
            'words' => array('lion', 'tiger'),
            'createsubdirectories' => false
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));

        // Test failing due to non-matching of attribute.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => false,
                'words' => array('cat', 'testvalue', 'dog'),
                'createsubdirectories' => false
            ),
            'attribute2' => (object)array(
                'allwords' => false,
                'words' => array('cow', 'horse', 'fishes'),
                'createsubdirectories' => false
            )
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));

        // Test matching array attribute.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => false,
                'words' => array('cat', 'testvalue', 'dog'),
                'createsubdirectories' => false
            )
        );
        $metadata = array(
            'attribute1' => array('big', 'small', 'testvalue'),
            'attribute2' => array('fish', 'whale', 'mermaid')
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Test failing to match array attribute.
        $filter['attribute2'] = (object)array(
            'allwords' => false,
            'words' => array('lion', 'tiger', 'bear'),
            'createsubdirectories' => false
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));
    }

    public function test_find_or_create_category() {

        $basecategory = $this->getDataGenerator()->create_category(array('name' => 'Base category'));

        // Test creating the course directly in the parent category.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => false
            )
        );
        $metadata = array(
            'attribute1' => 'testvalue',
            'attribute2' => 'fish'
        );
        $categoryids = filtering::find_or_create_categories($metadata, $filter, $basecategory->id);
        $this->assertEquals(array($basecategory->id), $categoryids);

        // Test creating the course directly in the parent category (with multiple attributes).
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => false
            ),
            'attribute2' => (object)array(
                'allwords' => false,
                'words' => array('fish', 'cat', 'dog'),
                'createsubdirectories' => false
            )
        );
        $categoryids = filtering::find_or_create_categories($metadata, $filter, $basecategory->id);
        $this->assertEquals(array($basecategory->id), $categoryids);

        // Test creating course in subcategory of parent category.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => true
            )
        );
        $categoryids = filtering::find_or_create_categories($metadata, $filter, $basecategory->id);
        $this->assertCount(1, $categoryids);
        $categoryid = reset($categoryids);
        $newcat = coursecat::get($categoryid);
        $this->assertEquals($metadata['attribute1'], $newcat->name);
        $parents = $newcat->get_parents();
        $parent = coursecat::get(array_pop($parents));
        $this->assertEquals($basecategory->id, $parent->id);

        // Test creating course in two levels of subcategories.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => true
            ),
            'attribute2' => (object)array(
                'allwords' => false,
                'words' => array('fish', 'cat', 'dog'),
                'createsubdirectories' => true
            )
        );
        $categoryids = filtering::find_or_create_categories($metadata, $filter, $basecategory->id);
        $this->assertCount(1, $categoryids);
        $categoryid = reset($categoryids);
        $newcat = coursecat::get($categoryid);
        $this->assertEquals($metadata['attribute2'], $newcat->name);
        $parents = $newcat->get_parents();
        $parent = coursecat::get(array_pop($parents));
        $this->assertEquals($metadata['attribute1'], $parent->name);
        $parent = coursecat::get(array_pop($parents));
        $this->assertEquals($basecategory->id, $parent->id);

        // Test creating course in subcategory from 2nd filter only.
        $filter = array(
            'attribute1' => (object)array(
                'allwords' => true,
                'words' => array(),
                'createsubdirectories' => false
            ),
            'attribute2' => (object)array(
                'allwords' => false,
                'words' => array('fish', 'cat', 'dog'),
                'createsubdirectories' => true
            )
        );
        $categoryids = filtering::find_or_create_categories($metadata, $filter, $basecategory->id);
        $this->assertCount(1, $categoryids);
        $categoryid = reset($categoryids);
        $newcat = coursecat::get($categoryid);
        $this->assertEquals($metadata['attribute2'], $newcat->name);
        $parents = $newcat->get_parents();
        $parent = coursecat::get(array_pop($parents));
        $this->assertEquals($basecategory->id, $parent->id);

        /*        // Test creating course in single level subcategories with multiple attribute values.
                $filter = array(
                    'attribute1' => (object)array(
                        'allwords' => true,
                        'words' => array(),
                        'createsubdirectories' => true
                    )
                );
                $metadata = array(
                    'attribute1' => array('testvalue', 'testvalue2'),
                    'attribute2' => array('fish', 'whale', 'mermaid')
                );
                $DB->setReturnValueAt($ins, 'insert_record', -6); // base > testvalue
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -7); // base > testvalue2
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $categoryid = \local_campusconnect\filtering::find_or_create_categories($metadata, $filter, -5);
                $this->assertEqual($categoryid, array(-6, -7));
        
                // Test creating course in single level subcategories with multiple attribute values (but limited words)
                $filter = array(
                    'attribute1' => (object)array(
                        'allwords' => false,
                        'words' => array('testvalue'),
                        'createsubdirectories' => true
                    )
                );
                $metadata = array(
                    'attribute1' => array('testvalue', 'testvalue2'),
                    'attribute2' => array('fish', 'whale', 'mermaid')
                );
                $DB->setReturnValueAt($ins, 'insert_record', -6); // base > testvalue
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $categoryid = \local_campusconnect\filtering::find_or_create_categories($metadata, $filter, -5);
                $this->assertEqual($categoryid, array(-6));
        
                // Test creating course in two  levels of subcategories with multiple attribute values
                $filter = array(
                    'attribute1' => (object)array(
                        'allwords' => true,
                        'words' => array(),
                        'createsubdirectories' => true
                    ),
                    'attribute2' => (object)array(
                        'allwords' => true,
                        'words' => array(),
                        'createsubdirectories'=> true
                    )
                );
                $metadata = array(
                    'attribute1' => array('testvalue', 'testvalue2'),
                    'attribute2' => array('fish', 'whale', 'mermaid')
                );
                $DB->setReturnValueAt($ins, 'insert_record', -6); // base > testvalue
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -7); // base > testvalue > fish
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -8); // base > testvalue > whale
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -9); // base > testvalue > mermaid
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -10);  // base > testvalue2
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -11);  // base > testvalue2 > fish
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -12); // base > testvalue2 > whale
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $DB->setReturnValueAt($ins, 'insert_record', -13); // base > testvalue2 > mermaid
                $DB->expectAt($ins++, 'insert_record', array('course_categories', '*'));
                $DB->expectAt($getf++, 'get_field', array('course_categories', 'id', '*'));
                $categoryid = \local_campusconnect\filtering::find_or_create_categories($metadata, $filter, -5);
                $this->assertEqual($categoryid, array(-7, -8, -9, -11, -12, -13));
        
                $DB->expectCallCount('insert_record', $ins);
                $DB->expectCallCount('get_field', $getf);*/
    }

    public function test_complex_metadata() {
        $metadata = (object)array(
            'title' => 'Test title',
            'organisationalUnits' => array(
                (object)array('id' => 5, 'title' => 'test1'),
                (object)array('id' => 6, 'title' => 'test2'),
            ),
            'groups' => array(
                (object)array(
                    'id' => 'group1',
                    'title' => 'group1title',
                    'lecturers' => array(
                        (object)array('firstName' => 'Fred', 'lastName' => 'Bloggs'),
                        (object)array('firstName' => 'Gary', 'lastName' => 'Barlow'),
                    )
                )
            ),
        );

        $ecs = new ecssettings();
        $ecs->save_settings(array(
                                'url' => 'http://localhost:3000',
                                'auth' => ecssettings::AUTH_NONE,
                                'ecsauth' => 'unittest1',
                                'importcategory' => 0,
                                'importrole' => 'student',
                            ));
        $meta = new metadata($ecs, false);
        $metadata = $meta->flatten_remote_data($metadata, false);

        // Check for matching organisationalUnits field.
        $filter = array(
            'organisationalUnits' => (object)array(
                'allwords' => false,
                'words' => array('test1', 'test2'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Check for non-matching organisationalUnits field.
        $filter = array(
            'organisationalUnits' => (object)array(
                'allwords' => false,
                'words' => array('test3', 'test4'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));

        // Check for matching groups field.
        $filter = array(
            'groups' => (object)array(
                'allwords' => false,
                'words' => array('group1title', 'group2title'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Check for non-matching groups field.
        $filter = array(
            'groups' => (object)array(
                'allwords' => false,
                'words' => array('group2title'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));

        // Check for matching groups_lecturers field.
        $filter = array(
            'groups_lecturers' => (object)array(
                'allwords' => false,
                'words' => array('Fred Bloggs', 'Robbie Williams'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertTrue(filtering::check_filter_match($metadata, $filter));

        // Check for non-matching groups_lecturers field.
        $filter = array(
            'groups_lecturers' => (object)array(
                'allwords' => false,
                'words' => array('Robbie Williams'),
                'createsubdirectories' => false,
            ),
        );
        $this->assertFalse(filtering::check_filter_match($metadata, $filter));
    }
}