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
 * Tests for the incoming directory tree notifications for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_campusconnect\directory;
use local_campusconnect\directorytree;
use local_campusconnect\event;
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

global $CFG;
require_once($CFG->dirroot.'/local/campusconnect/tests/testbase.php');

/**
 * Class local_campusconnect_directorytree_test
 * @group local_campusconnect
 */
class local_campusconnect_directorytree_test extends campusconnect_base_testcase {
    public function setUp() {
        parent::setUp();
        $this->clear_ecs_resources(event::RES_DIRECTORYTREE);

        directorytree::set_enabled(true);
        directory::clear_directory_cache();
    }

    protected function tearDown() {
        $this->clear_ecs_resources(event::RES_DIRECTORYTREE);
        parent::tearDown();
    }

    public function test_directorytree_class() {
        $data = (object)array(
            'id' => -1,
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
            'categoryid' => null,
            'mappingmode' => directorytree::MODE_PENDING,
            'takeovertitle' => true,
            'takeoverposition' => true,
            'takeoverallocation' => true
        );

        $dirtree = new directorytree($data);

        $this->assertEquals($data->rootid, $dirtree->get_root_id());
        $this->assertEquals($data->mappingmode, $dirtree->get_mode());
        $this->assertEquals($data->title, $dirtree->get_title());
        $this->assertEquals($data->categoryid, $dirtree->get_category_id());
        $this->assertEquals($data->takeovertitle, $dirtree->should_take_over_title());
        $this->assertEquals($data->takeoverposition, $dirtree->should_take_over_position());
        $this->assertEquals($data->takeoverallocation, $dirtree->should_take_over_allocation());
    }

    public function test_directorytree_create() {
        $data = (object)array(
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
            // Expected default values.
            'categoryid' => null,
            'mappingmode' => directorytree::MODE_PENDING,
            'takeovertitle' => true,
            'takeoverposition' => true,
            'takeoverallocation' => true
        );

        $dirtree = new directorytree();
        $dirtree->create($data->resourceid, $data->rootid, $data->title, $data->ecsid, $data->mid);

        // Check the tree object has been set up as expected.
        $this->assertEquals($data->rootid, $dirtree->get_root_id());
        $this->assertEquals($data->mappingmode, $dirtree->get_mode());
        $this->assertEquals($data->title, $dirtree->get_title());
        $this->assertEquals($data->categoryid, $dirtree->get_category_id());
        $this->assertEquals($data->takeovertitle, $dirtree->should_take_over_title());
        $this->assertEquals($data->takeoverposition, $dirtree->should_take_over_position());
        $this->assertEquals($data->takeoverallocation, $dirtree->should_take_over_allocation());

        // Check the data can be retrieved from the database.
        $dirtree = directorytree::get_by_root_id($data->rootid);
        $this->assertEquals($data->rootid, $dirtree->get_root_id());
        $this->assertEquals($data->mappingmode, $dirtree->get_mode());
        $this->assertEquals($data->title, $dirtree->get_title());
        $this->assertEquals($data->categoryid, $dirtree->get_category_id());
        $this->assertEquals($data->takeovertitle, $dirtree->should_take_over_title());
        $this->assertEquals($data->takeoverposition, $dirtree->should_take_over_position());
        $this->assertEquals($data->takeoverallocation, $dirtree->should_take_over_allocation());
    }

    public function test_directorytree_set_title_and_category() {
        global $DB;

        $data = (object)array(
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
        );

        // Check updating the title, when no category id assigned.
        $newtitle = 'Change title';
        $dirtree = new directorytree();
        $dirtree->create($data->resourceid, $data->rootid, $data->title, $data->ecsid, $data->mid);
        $dirtree->set_title($newtitle);

        $this->assertEquals($newtitle, $dirtree->get_title());
        $dirtree = directorytree::get_by_root_id($data->rootid);
        $this->assertEquals($newtitle, $dirtree->get_title());

        // Set the category and make sure the name is updated.
        $this->assertEquals(directorytree::MODE_PENDING, $dirtree->get_mode());
        $category = $this->getDataGenerator()->create_category(array('name' => 'Original name'));
        $dirtree->map_category($category->id);

        $this->assertEquals($category->id, $dirtree->get_category_id());
        $this->assertEquals(directorytree::MODE_WHOLE, $dirtree->get_mode());
        $dirtree = directorytree::get_by_root_id($data->rootid);
        $this->assertEquals($category->id, $dirtree->get_category_id());
        $this->assertEquals(directorytree::MODE_WHOLE, $dirtree->get_mode());
        $this->assertEquals($newtitle, $DB->get_field('course_categories', 'name', array('id' => $category->id)));

        // Set the title again and make sure the category name is updated.
        $newtitle = 'A different title';
        $dirtree->set_title($newtitle);

        $this->assertEquals($category->id, $dirtree->get_category_id());
        $dirtree = directorytree::get_by_root_id($data->rootid);
        $this->assertEquals($category->id, $dirtree->get_category_id());
        $this->assertEquals($newtitle, $DB->get_field('course_categories', 'name', array('id' => $category->id)));

        // Turn off 'take over title' and check the title is no longer updated in the category.
        $anothernewtitle = 'Another change of title';
        $dirtree->update_settings(array('takeovertitle' => false));
        $dirtree->set_title($anothernewtitle);

        $this->assertEquals($category->id, $dirtree->get_category_id());
        $dirtree = directorytree::get_by_root_id($data->rootid);
        $this->assertEquals($category->id, $dirtree->get_category_id());
        $this->assertEquals($newtitle, $DB->get_field('course_categories', 'name', array('id' => $category->id)));
    }

    public function test_directorytree_set_mode() {
        $data = (object)array(
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
        );

        $dirtree = new directorytree();
        $dirtree->create($data->resourceid, $data->rootid, $data->title, $data->ecsid, $data->mid);

        $this->assertEquals(directorytree::MODE_PENDING, $dirtree->get_mode());

        $dirtree->set_mode(directorytree::MODE_WHOLE);
        $this->assertEquals(directorytree::MODE_WHOLE, $dirtree->get_mode());

        $dirtree->set_mode(directorytree::MODE_MANUAL);
        $this->assertEquals(directorytree::MODE_MANUAL, $dirtree->get_mode());

        try {
            $dirtree->set_mode(directorytree::MODE_WHOLE);
            $this->fail('Should not be able to change tree mode from MANUAL => WHOLE');
        } catch (coding_exception $e) {
            // Exception occurred as expected.
        }

        try {
            $dirtree->set_mode(directorytree::MODE_PENDING);
            $this->fail('Should not be able to change tree mode from MANUAL => PENDING');
        } catch (coding_exception $e) {
            // Exception occurred as expected.
        }

        $data->rootid = 9;
        $dirtree = new directorytree();
        $dirtree->create($data->resourceid, $data->rootid, $data->title, $data->ecsid, $data->mid);
        $dirtree->set_mode(directorytree::MODE_WHOLE);
        try {
            $dirtree->set_mode(directorytree::MODE_PENDING);
            $this->fail('Should not be able to change tree mode from WHOLE => PENDING');
        } catch (coding_exception $e) {
            // Exception occurred as expected.
        }
    }

    public function test_directorytree_delete() {
        $data = (object)array(
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
        );

        $dirtree = new directorytree();
        $dirtree->create($data->resourceid, $data->rootid, $data->title, $data->ecsid, $data->mid);
        $dirtree->delete();

        $this->assertEquals(directorytree::MODE_DELETED, $dirtree->get_mode());

        try {
            $dirtree->set_mode(directorytree::MODE_WHOLE);
            $this->fail('Should not be able to change tree mode from DELETED => WHOLE');
        } catch (coding_exception $e) {
            // Exception occurred as expected.
        }

        try {
            $dirtree->set_mode(directorytree::MODE_MANUAL);
            $this->fail('Should not be able to change tree mode from DELETED => MANUAL');
        } catch (coding_exception $e) {
            // Exception occurred as expected.
        }
    }

    public function test_list_directory_trees() {
        $treedata1 = (object)array(
            'resourceid' => 5,
            'rootid' => 8,
            'title' => 'Test directory',
            'ecsid' => -1,
            'mid' => 14,
        );

        $treedata2 = (object)array(
            'resourceid' => 5,
            'rootid' => 10,
            'title' => 'Test directory2',
            'ecsid' => -1,
            'mid' => 14,
        );

        $treedata3 = (object)array(
            'resourceid' => 5,
            'rootid' => 15,
            'title' => 'Test directory3',
            'ecsid' => -1,
            'mid' => 14,
        );

        $tree1 = new directorytree();
        $tree1->create($treedata1->resourceid, $treedata1->rootid, $treedata1->title, $treedata1->ecsid, $treedata1->mid);
        $tree2 = new directorytree();
        $tree2->create($treedata2->resourceid, $treedata2->rootid, $treedata2->title, $treedata2->ecsid, $treedata2->mid);
        $tree3 = new directorytree();
        $tree3->create($treedata3->resourceid, $treedata3->rootid, $treedata3->title, $treedata3->ecsid, $treedata3->mid);

        // List all directories.
        $trees = directorytree::list_directory_trees();
        $this->assertCount(3, $trees);
        $this->assertEquals($tree1, array_shift($trees));
        $this->assertEquals($tree2, array_shift($trees));
        $this->assertEquals($tree3, array_shift($trees));

        // Check deleted directories are excluded.
        $tree2->delete();
        $trees = directorytree::list_directory_trees();
        $this->assertCount(2, $trees);
        $this->assertEquals($tree1, array_shift($trees));
        $this->assertEquals($tree3, array_shift($trees));

        // Check deleted directories can be included on request.
        $trees = directorytree::list_directory_trees(true);
        $this->assertCount(3, $trees);
        $this->assertEquals($tree1, array_shift($trees));
        $this->assertEquals($tree2, array_shift($trees));
        $this->assertEquals($tree3, array_shift($trees));
    }

    public function test_directorytree_refresh_create() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);

        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Check the directory tree has been created as expected.
        $trees = directorytree::list_directory_trees();
        $this->assertCount(1, $trees);
        /** @var directorytree $tree */
        $tree = array_shift($trees);
        $this->assertEquals($dirtree->rootID, $tree->get_root_id());
        $this->assertEquals($dirtree->directoryTreeTitle, $tree->get_title());

        // Check the directories within the tree.
        $dirs = directory::get_toplevel_directories($tree->get_root_id());
        $this->assertCount(1, $dirs);
        /** @var directory $topdir */
        $topdir = array_shift($dirs);
        $this->assertEquals(6, $topdir->get_directory_id());
        $this->assertEquals('First directory', $topdir->get_title());
        $this->assertEquals(null, $topdir->get_parent());

        $subdirs = $topdir->get_children();
        $this->assertCount(2, $subdirs);
        $subdir = array_shift($subdirs);
        $this->assertEquals(7, $subdir->get_directory_id());
        $this->assertEquals('Second directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
        $subdir = array_shift($subdirs);
        $this->assertEquals(8, $subdir->get_directory_id());
        $this->assertEquals('Third directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
    }

    public function test_directorytree_refresh_update() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $eid = $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        $dirtree->directoryTreeTitle = 'Testing directory tree (changed)'; // Renamed tree.
        $dirtree->nodes[0]->title = 'First directory (changed)'; // Rename subdirectory.
        // Remove the existing 'third directory' and create a new directory as a subdirectory of 'second directory'.
        $dirtree->nodes[2]->id = 9;
        $dirtree->nodes[2]->parent->id = 7;

        // Update the directorytree resource.
        $this->connect[1]->update_resource($eid, event::RES_DIRECTORYTREE, $dirtree, $this->community, null);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Check the directory tree has been created as expected.
        $trees = directorytree::list_directory_trees();
        $this->assertCount(1, $trees);
        /** @var directorytree $tree */
        $tree = array_shift($trees);
        $this->assertEquals(5, $tree->get_root_id());
        $this->assertEquals('Testing directory tree (changed)', $tree->get_title());

        // Check the directories within the tree.
        $dirs = directory::get_toplevel_directories($tree->get_root_id());
        $this->assertCount(1, $dirs);
        /** @var directory $topdir */
        $topdir = array_shift($dirs);
        $this->assertEquals(6, $topdir->get_directory_id());
        $this->assertEquals('First directory (changed)', $topdir->get_title());
        $this->assertEquals(null, $topdir->get_parent());

        $subdirs = $topdir->get_children();
        $this->assertCount(2, $subdirs);
        /** @var directory $subdir */
        $subdir = array_shift($subdirs);
        $this->assertEquals(7, $subdir->get_directory_id());
        $this->assertEquals('Second directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
        $deldir = array_shift($subdirs);
        $this->assertEquals(directory::STATUS_DELETED, $deldir->get_status());

        $subdirs = $subdir->get_children();
        $this->assertCount(1, $subdirs);
        $leafdir = array_shift($subdirs);
        $this->assertEquals(9, $leafdir->get_directory_id());
        $this->assertEquals('Third directory', $leafdir->get_title());
        $this->assertEquals($subdir, $leafdir->get_parent());
    }

    public function test_directorytree_refresh_delete() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $eid = $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Delete the directorytree resource.
        $this->connect[1]->delete_resource($eid, event::RES_DIRECTORYTREE);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Check the directory tree does not exist.
        $trees = directorytree::list_directory_trees();
        $this->assertEmpty($trees);
    }

    public function test_directorytree_directory_create() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);

        // Process the queued messages.
        $queue = new receivequeue();
        $queue->update_from_ecs($this->connect[2]);
        $queue->process_queue($this->connect[2]->get_settings());

        // Check the directory tree has been created as expected.
        $trees = directorytree::list_directory_trees();
        $this->assertCount(1, $trees);
        /** @var directorytree $tree */
        $tree = array_shift($trees);
        $this->assertEquals($dirtree->rootID, $tree->get_root_id());
        $this->assertEquals($dirtree->directoryTreeTitle, $tree->get_title());

        // Check the directories within the tree.
        $dirs = directory::get_toplevel_directories($tree->get_root_id());
        $this->assertCount(1, $dirs);
        /** @var directory $topdir */
        $topdir = array_shift($dirs);
        $this->assertEquals(6, $topdir->get_directory_id());
        $this->assertEquals('First directory', $topdir->get_title());
        $this->assertEquals(null, $topdir->get_parent());

        $subdirs = $topdir->get_children();
        $this->assertCount(2, $subdirs);
        $subdir = array_shift($subdirs);
        $this->assertEquals(7, $subdir->get_directory_id());
        $this->assertEquals('Second directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
        $subdir = array_shift($subdirs);
        $this->assertEquals(8, $subdir->get_directory_id());
        $this->assertEquals('Third directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
    }

    public function test_directorytree_directory_update() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $eid = $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        $dirtree->directoryTreeTitle = 'Testing directory tree (changed)'; // Renamed tree.
        $dirtree->nodes[0]->title = 'First directory (changed)'; // Rename subdirectory.
        // Remove the existing 'third directory' and create a new directory as a subdirectory of 'second directory'.
        $dirtree->nodes[2]->id = 9;
        $dirtree->nodes[2]->parent->id = 7;

        // Update the directorytree resource.
        $this->connect[1]->update_resource($eid, event::RES_DIRECTORYTREE, $dirtree, $this->community, null);

        // Process the queued messages.
        $queue = new receivequeue();
        $queue->update_from_ecs($this->connect[2]);
        $queue->process_queue($this->connect[2]->get_settings());

        // Check the directory tree has been created as expected.
        $trees = directorytree::list_directory_trees();
        $this->assertCount(1, $trees);
        /** @var directorytree $tree */
        $tree = array_shift($trees);
        $this->assertEquals(5, $tree->get_root_id());
        $this->assertEquals('Testing directory tree (changed)', $tree->get_title());

        // Check the directories within the tree.
        $dirs = directory::get_toplevel_directories($tree->get_root_id());
        $this->assertCount(1, $dirs);
        /** @var directory $topdir */
        $topdir = array_shift($dirs);
        $this->assertEquals(6, $topdir->get_directory_id());
        $this->assertEquals('First directory (changed)', $topdir->get_title());
        $this->assertEquals(null, $topdir->get_parent());

        $subdirs = $topdir->get_children();
        $this->assertCount(2, $subdirs);
        /** @var directory $subdir */
        $subdir = array_shift($subdirs);
        $this->assertEquals(7, $subdir->get_directory_id());
        $this->assertEquals('Second directory', $subdir->get_title());
        $this->assertEquals($topdir, $subdir->get_parent());
        $deldir = array_shift($subdirs);
        $this->assertEquals(directory::STATUS_DELETED, $deldir->get_status());

        $subdirs = $subdir->get_children();
        $this->assertCount(1, $subdirs);
        $leafdir = array_shift($subdirs);
        $this->assertEquals(9, $leafdir->get_directory_id());
        $this->assertEquals('Third directory', $leafdir->get_title());
        $this->assertEquals($subdir, $leafdir->get_parent());
    }

    public function test_directorytree_directory_delete() {
        $dirtree = (object)array(
            'rootID' => '5',
            'directoryTreeTitle' => 'Testing directory tree',
            'term' => '2',
            'nodes' => array(
                (object)array(
                    'id' => '6',
                    'title' => 'First directory',
                    'parent' => (object)array(
                        'id' => '5',
                    )
                ),
                (object)array(
                    'id' => '7',
                    'title' => 'Second directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                ),
                (object)array(
                    'id' => '8',
                    'title' => 'Third directory',
                    'parent' => (object)array(
                        'id' => '6',
                    )
                )
            ),
        );

        // Add a directorytree resource.
        $eid = $this->connect[1]->add_resource(event::RES_DIRECTORYTREE, $dirtree, $this->community, null);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Delete the directorytree resource.
        $this->connect[1]->delete_resource($eid, event::RES_DIRECTORYTREE);
        directorytree::refresh_from_ecs($this->connect[2]->get_settings());

        // Process the queued messages.
        $queue = new receivequeue();
        $queue->update_from_ecs($this->connect[2]);
        $queue->process_queue($this->connect[2]->get_settings());

        // Check the directory tree does not exist.
        $trees = directorytree::list_directory_trees();
        $this->assertEmpty($trees);
    }
}
