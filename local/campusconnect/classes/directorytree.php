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
 * Main connection class for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class directorytree {

    const MODE_PENDING = 0;
    const MODE_WHOLE = 1;
    const MODE_MANUAL = 2;
    const MODE_DELETED = 3;

    /** @var int $recordid */
    protected $recordid = null;
    protected $resourceid = null;
    protected $rootid = null;
    protected $title = null;
    protected $ecsid = null;
    protected $mid = null;
    protected $categoryid = null;
    protected $mappingmode = null;

    protected $takeovertitle = null;
    protected $takeoverposition = null;
    protected $takeoverallocation = null;

    protected $stillexists = false;

    protected static $dbfields = array(
        'resourceid', 'rootid', 'title', 'ecsid', 'mid', 'categoryid', 'mappingmode',
        'takeovertitle', 'takeoverposition', 'takeoverallocation'
    );
    protected static $createemptycategories = null;
    protected static $enabled = null;

    public function __construct($data = null) {
        if ($data) {
            // Local_campusconnect_dirroot record loaded from DB.
            $this->set_data($data);
        }
    }

    public function get_root_id() {
        return $this->rootid;
    }

    public function get_mode() {
        return $this->mappingmode;
    }

    public function is_deleted() {
        return ($this->mappingmode == self::MODE_DELETED);
    }

    public function get_title() {
        return $this->title;
    }

    public function get_category_id() {
        return $this->categoryid;
    }

    public function should_take_over_title() {
        return $this->takeovertitle;
    }

    public function should_take_over_position() {
        return $this->takeoverposition;
    }

    public function should_take_over_allocation() {
        return $this->takeoverallocation;
    }

    public function update_settings($newsettings) {
        global $DB;

        $newsettings = (array)$newsettings;
        $newsettings = (object)$newsettings;

        if (isset($newsettings->takeovertitle) && $newsettings->takeovertitle != $this->takeovertitle) {
            $this->update_field('takeovertitle', $newsettings->takeovertitle);
            if ($this->takeovertitle && $this->categoryid) {
                $DB->set_field('course_categories', 'name', $this->title, array('id' => $this->categoryid));
            }
        }

        if (isset($newsettings->takeoverposition) && $newsettings->takeoverposition != $this->takeoverposition) {
            $this->update_field('takeoverposition', $newsettings->takeoverposition);
        }

        if (isset($newsettings->takeoverallocation) && $newsettings->takeoverallocation != $this->takeoverallocation) {
            $this->update_field('takeoverallocation', $newsettings->takeoverallocation);
        }
    }

    /**
     * Used during ECS updates to track any trees that no longer
     * exist on the ECS server
     * @return bool - true if still exists
     */
    public function still_exists() {
        return $this->stillexists;
    }

    /**
     * Internal function to set the data loaded from the DB
     * @param object $data - record from the database
     */
    protected function set_data($data) {
        $this->recordid = $data->id;
        foreach (self::$dbfields as $field) {
            if (isset($data->$field)) {
                $this->$field = $data->$field;
            }
        }
    }

    /**
     * Internal function to set the value of a specified field
     * @param string $field
     * @param mixed $value
     */
    protected function update_field($field, $value) {
        global $DB;
        $DB->set_field('local_campusconnect_dirroot', $field, $value, array('id' => $this->recordid));
        $this->$field = $value;
    }

    /**
     * Create a database entry for a new directory tree
     * @param int $resourceid - id of the resource in the ECS
     * @param int $rootid - id of root node
     * @param string $title
     * @param int $ecsid
     * @param int $mid
     */
    public function create($resourceid, $rootid, $title, $ecsid, $mid) {
        global $DB;

        $ins = new stdClass();
        $ins->resourceid = $resourceid;
        $ins->rootid = $rootid;
        $ins->title = $title;
        $ins->ecsid = $ecsid;
        $ins->mid = $mid;
        $ins->categoryid = null;
        $ins->mappingmode = self::MODE_PENDING;
        $ins->takeovertitle = true;
        $ins->takeoverposition = true;
        $ins->takeoverallocation = true;

        $ins->id = $DB->insert_record('local_campusconnect_dirroot', $ins);
        $this->set_data($ins);
    }

    /**
     * Set the title of the directory tree (and update the mapped category,
     * if needed)
     * @param string $title
     */
    public function set_title($title) {
        global $DB;

        if ($this->mappingmode == self::MODE_DELETED) {
            throw new coding_exception("Cannot change the title of deleted directory trees");
        }

        if (empty($title)) {
            throw new coding_exception("Directory tree title cannot be empty");
        }

        if ($title == $this->title) {
            return;
        }

        $this->update_field('title', $title);

        if ($this->categoryid && $this->takeovertitle) {
            $DB->set_field('course_categories', 'name', $title, array('id' => $this->categoryid));
        }
    }

    /**
     * Map this directory tree onto a course category
     * @param int $categoryid
     * @return mixed string | null - error string if there is a problem
     */
    public function map_category($categoryid) {
        global $DB;

        if ($this->mappingmode == self::MODE_DELETED) {
            throw new coding_exception("Cannot map deleted directory trees");
        }

        if ($this->categoryid == $categoryid) {
            return; // No change.
        }

        if (!$newcategory = $DB->get_record('course_categories', array('id' => $categoryid))) {
            throw new coding_exception("Directory tree - attempting to map onto non-existent category $categoryid");
        }

        $oldcategoryid = $this->categoryid;
        $this->update_field('categoryid', $categoryid);

        if ($this->title && $this->takeovertitle) {
            $DB->set_field('course_categories', 'name', $this->title, array('id' => $this->categoryid));
        }

        if ($this->mappingmode == self::MODE_PENDING) {
            $this->set_mode(self::MODE_WHOLE);
        }

        if ($oldcategoryid) {
            // Move directories within this directory tree.
            directory::move_category($this->rootid, $oldcategoryid, $this->categoryid);
        } else {
            // Create all categories, if needed.
            if (self::should_create_empty_categories()) {
                $this->create_all_categories();
            }
        }

        return null;
    }

    /**
     * Remove the category mapping.
     */
    public function unmap_category() {
        if ($this->mappingmode == self::MODE_DELETED) {
            throw new coding_exception("Cannot unmap deleted directory trees");
        }

        if (empty($this->categoryid)) {
            return; // Nothing to do.
        }

        $this->update_field('categoryid', null);
    }

    /**
     * Set the mapping mode for this directory tree
     * @param int $mode - self::MODE_PENDING, self::MODE_WHOLE, self::MODE_MANUAL
     */
    public function set_mode($mode) {
        if ($mode == $this->mappingmode) {
            return; // No change.
        }

        if (!in_array($mode, array(self::MODE_PENDING, self::MODE_WHOLE, self::MODE_MANUAL))) {
            throw new coding_exception("Invalid directory tree mode $mode");
        }
        if ($mode == self::MODE_PENDING) {
            throw new coding_exception("Directory tree - unable to switch to MODE_PENDING");
        }
        if ($this->mappingmode == self::MODE_MANUAL) {
            throw new coding_exception("Directory tree - unable to switch from MODE_MANUAL");
        }
        if ($this->mappingmode == self::MODE_DELETED) {
            throw new coding_exception("Directory tree - unable to switch from MODE_DELETED");
        }
        $oldmode = $this->mappingmode;

        $this->update_field('mappingmode', $mode);

        if ($oldmode == self::MODE_PENDING) {
            if ($this->categoryid && self::should_create_empty_categories()) {
                $this->create_all_categories();
            }
        }
    }

    /**
     * Mark this directory tree as still existing on the ECS server
     */
    public function set_still_exists() {
        $this->stillexists = true;
        if ($this->mappingmode == self::MODE_DELETED) {
            //throw new coding_exception("ECS updating directory tree that is marked as deleted");
            // Not sure how it ended up being marked as deleted, but try to resurrect it now.
            $this->update_field('mappingmode', self::MODE_PENDING);
        }
    }

    /**
     * Mark the directory tree as deleted
     */
    public function delete() {
        directory::delete_root_directory($this->rootid);
        $this->update_field('mappingmode', self::MODE_DELETED);

        notification::queue_message($this->ecsid,
                                    notification::MESSAGE_DIRTREE,
                                    notification::TYPE_DELETE,
                                    $this->rootid);
    }

    /**
     * Retrieve a directory from within this tree
     * @param int $directoryid
     * @return mixed directory | bool - false if not found
     */
    public function get_directory($directoryid) {
        $dirs = directory::get_directories($this->rootid);
        foreach ($dirs as $dir) {
            if ($dir->get_directory_id() == $directoryid) {
                return $dir;
            }
        }
        return false;
    }

    /**
     * Lists all current directory => category mappings
     * (used by the javascript front end)
     * @return int[] of directoryid => categoryid
     */
    public function list_all_mappings() {
        $rootmapping = new stdClass();
        $rootmapping->category = intval($this->categoryid);
        $rootmapping->canunmap = true;
        $rootmapping->canmap = true;
        $ret = array($this->rootid => $rootmapping);
        $dirs = directory::get_directories($this->rootid);
        foreach ($dirs as $dir) {
            $mapping = new stdClass();
            $mapping->category = intval($dir->get_category_id());
            $mapping->canunmap = $dir->can_unmap();
            $mapping->canmap = $dir->can_map();
            $mapping->dirid = $dir->get_directory_id();
            $ret[$mapping->dirid] = $mapping;
        }
        return $ret;
    }

    /**
     * Called if 'create empty categories' is set, to create all categories for this tree.
     */
    public function create_all_categories() {
        directory::create_all_categories($this->rootid, $this->categoryid);
    }

    public static function should_create_empty_categories() {
        if (is_null(self::$createemptycategories)) {
            self::$createemptycategories = get_config('local_campusconnect', 'createemptycategories');
        }
        return self::$createemptycategories;
    }

    public static function enabled() {
        if (is_null(self::$enabled)) {
            self::$enabled = get_config('local_campusconnect', 'directorymappingenabled');
        }
        return self::$enabled;
    }

    public static function set_enabled($enabled) {
        set_config('directorymappingenabled', $enabled, 'local_campusconnect');
        self::$enabled = $enabled;
    }

    public static function set_create_empty_categories($enabled) {
        set_config('createemptycategories', $enabled, 'local_campusconnect');
        self::$createemptycategories = $enabled;
        if ($enabled) {
            $trees = self::list_directory_trees();
            foreach ($trees as $tree) {
                $catid = $tree->get_category_id();
                directory::create_all_categories($tree->get_root_id(), $catid);
            }
        }
    }

    /**
     * Get a list of all directory trees loaded from ECS servers (only one ECS server
     * and one mid should be providing these, so no parameters needed)
     * @param bool $includedeleted set to true to include deleted trees
     * @return directorytree[]
     */
    public static function list_directory_trees($includedeleted = false) {
        global $DB;

        $sort = 'id';
        if ($includedeleted) {
            $trees = $DB->get_records('local_campusconnect_dirroot', null, $sort);
        } else {
            $trees = $DB->get_records_select('local_campusconnect_dirroot', 'mappingmode <> ?', array(self::MODE_DELETED), $sort);
        }
        return array_map(function ($data) {
            return new directorytree($data);
        }, $trees);
    }

    /**
     * Get a single directory tree, identified by its rootid
     * @param int $rootid
     * @return directorytree
     */
    public static function get_by_root_id($rootid) {
        global $DB;

        $tree = $DB->get_record('local_campusconnect_dirroot', array('rootid' => $rootid), '*', MUST_EXIST);
        return new directorytree($tree);
    }

    /**
     * If the directory tree format matches the old schema, then update it to the new schema
     * @param $directories
     */
    public static function convert_from_old_schema($directories) {
        if (isset($directories->nodes)) {
            if ($directories->nodes) {
                if ($directories->nodes[0]->id == $directories->rootID) {
                    return; // Root node is already in the list of nodes.
                }
            }

            // New schema - push a root node into the list of nodes for easier processing.
            $node = (object)array(
                'id' => $directories->rootID,
                'title' => $directories->directoryTreeTitle,
                'term' => isset($directories->term) ? $directories->term : null,
                'parent' => (object)array(
                    'id' => 0,
                )
            );
            array_unshift($directories->nodes, $node);
        } else {
            // Old schema - create 'nodes' array from the single directory structure.
            $node = (object)array(
                'id' => $directories->id,
                'title' => $directories->title,
                'parent' => (object)array(
                    'id' => $directories->parent->id
                )
            );
            if (isset($directories->order)) {
                $node->order = $directories->order;
            }
            if (isset($directories->term)) {
                $node->term = $directories->term;
            }
            if (isset($directories->parent->title)) {
                $node->parent->title = $directories->parent->title;
            }
            $directories->nodes = array($node);
        }
    }

    /**
     * Full update of all directory trees from ECS
     * @param ecssettings $ecssettings
     * @return object an object containing: ->created = array of resourceids created
     *                            ->updated = array of resourceids updated
     *                            ->deleted = array of resourceids deleted
     */
    public static function refresh_from_ecs(ecssettings $ecssettings) {
        $ret = (object)array('created' => array(), 'updated' => array(), 'deleted' => array(), 'errors' => array());

        if (!self::enabled()) {
            return $ret; // Mapping disabled.
        }

        /** @var $cms participantsettings */
        if (!$cms = participantsettings::get_cms_participant()) {
            return $ret;
        }

        if ($cms->get_ecs_id() != $ecssettings->get_id()) {
            return $ret; // Not refreshing the ECS the CMS is connected to.
        }

        // Gather directory changes from the ECS server.
        if (!$ecssettings->is_enabled()) {
            return $ret; // Ignore disabled ECS.
        }

        $trees = self::list_directory_trees(true);
        /** @var $currenttrees directorytree[] */
        $currenttrees = array();
        foreach ($trees as $tree) {
            $currenttrees[$tree->get_root_id()] = $tree;
        }
        unset($trees);

        directory::clear_directory_cache(); // Make sure there is no stale directory data.

        $connect = new connect($ecssettings);
        $resources = $connect->get_resource_list(event::RES_DIRECTORYTREE);
        foreach ($resources->get_ids() as $resourceid) {
            $directories = $connect->get_resource($resourceid, event::RES_DIRECTORYTREE);

            if (!$directories) {
                // Resource failed to download - not sure why that would ever happen, but just skip it.
                $ret->errors[] = get_string('faileddownload', 'local_campusconnect',
                                            event::RES_DIRECTORYTREE.'/'.$resourceid);
                continue;
            }

            if (is_array($directories)) {
                $directories = reset($directories);
            }
            self::convert_from_old_schema($directories); // Handle any directory trees matching the old version of the schema.

            foreach ($directories->nodes as $directory) {
                if ($directory->parent->id) {
                    // Not a root directory.
                    directory::check_update_directory($resourceid, $directory, $directories->rootID);
                    continue;
                }

                if ($directory->id != $directories->rootID) {
                    log::add("Root directory id ($directory->id) does not match the rootID ($directories->rootID)");
                    log::add_object($directories);
                    throw new directorytree_exception("Root directory id ($directory->id) does not match the rootID".
                                                      " ($directories->rootID) - see log file for details");
                }
                if ($directory->title != $directories->directoryTreeTitle) {
                    log::add("Root directory title ($directory->title) does not match the directoryTreeTitle".
                             " ($directories->directoryTreeTitle)");
                    log::add_object($directories);
                    throw new directorytree_exception("Root directory title ($directory->title) does not match".
                                                      " the directoryTreeTitle ($directories->directoryTreeTitle) - ".
                                                      "see log file for details");
                }

                if (array_key_exists($directory->id, $currenttrees)) {
                    // Update existing tree.
                    $currenttrees[$directory->id]->set_still_exists(); // So we can track any trees that no longer exist on ECS.
                    $currenttrees[$directory->id]->set_title($directory->title);
                    $ret->updated[] = $currenttrees[$directory->id]->resourceid;
                } else {
                    // Create new tree.
                    $newtree = new directorytree();
                    $newtree->create($resourceid, $directory->id, $directory->title, $cms->get_ecs_id(), $cms->get_mid());
                    $currenttrees[$newtree->get_root_id()] = $newtree;
                    $newtree->set_still_exists(); // So we can track any trees that no longer exist on ECS.
                    $ret->created[] = $newtree->resourceid;
                }
            }
        }

        // Check if any new categories need to be created.
        directory::process_new_directories();

        // Update any trees that no longer exist on the ECS.
        foreach ($currenttrees as $tree) {
            if (!$tree->still_exists() && !$tree->is_deleted()) {
                $tree->delete(); // Will also delete any contained directories.
                $ret->deleted[] = $tree->resourceid;
            } else {
                directory::remove_missing_directories($tree->get_root_id());
            }
        }

        // Look for any directories mapped on to categories that no longer exist.
        self::check_all_mappings();
        return $ret;
    }

    /**
     * Used by the ECS event processing to create/update directories / directory trees
     * @param int $resourceid - the ID on the ECS server
     * @param ecssettings $ecssettings - the ECS being connected to
     * @param object|object[] $directories - the resource data from ECS
     * @param details $details - the metadata for the resource on the ECS
     * @return bool true if successful
     */
    public static function update_directory($resourceid, ecssettings $ecssettings, $directories, details $details) {
        global $DB;

        $mid = $details->get_sender_mid();
        $ecsid = $ecssettings->get_id();
        $cms = participantsettings::get_cms_participant();
        if (!$cms || $cms->get_mid() != $mid || $cms->get_ecs_id() != $ecsid) {
            log::add("Warning: received update directory ({$resourceid}) event from non-CMS participant");
            return true;
        }

        if (is_array($directories)) {
            $directories = reset($directories);
        }
        self::convert_from_old_schema($directories);

        foreach ($directories->nodes as $directory) {
            $isdirectorytree = $directory->parent->id ? false : true;
            if ($isdirectorytree) {
                if ($currdirtree = $DB->get_record('local_campusconnect_dirroot', array('rootid' => $directories->rootID))) {
                    $tree = new directorytree($currdirtree);
                    $tree->set_title($directory->title);
                } else {
                    $tree = new directorytree();
                    $tree->create($resourceid, $directories->rootID, $directory->title, $ecsid, $mid);
                }
            } else {
                directory::check_update_directory($resourceid, $directory, $directories->rootID);
            }
        }

        return true;
    }

    /**
     * Used by the ECS event processing to delete directories / directory trees
     * @param int $resourceid - the ID on the ECS server
     * @param ecssettings $ecssettings - the ECS being connected to
     * @return bool true if successful
     */
    public static function delete_directory($resourceid, ecssettings $ecssettings) {
        global $DB;

        $cms = participantsettings::get_cms_participant();
        if (!$cms || $ecssettings->get_id() != $cms->get_ecs_id()) {
            log::add("Warning: received delete directory ({$resourceid}) event from non-CMS participant");
            return true;
        }

        $dirtrees = $DB->get_records('local_campusconnect_dirroot', array('resourceid' => $resourceid));
        foreach ($dirtrees as $dirtree) {
            $dirtree = new directorytree($dirtree);
            $dirtree->delete();
        }

        $dirs = $DB->get_records('local_campusconnect_dir', array('resourceid' => $resourceid));
        foreach ($dirs as $dir) {
            $dir = new directory($dir);
            $dir->delete();
        }

        return true;
    }

    /**
     * Go through the list of directories from the ECS and remove any local directories that have the same resource id,
     * but are not in the list from the ECS
     * @param int $resourceid the resourceid that these directories are associated with
     * @param ecssettings $ecssettings
     * @param object|object[] $directories the list of directories from the ECS
     * @param details $details
     */
    public static function delete_missing_directories($resourceid, ecssettings $ecssettings, $directories, details $details) {
        global $DB;

        $mid = $details->get_sender_mid();
        $ecsid = $ecssettings->get_id();
        $cms = participantsettings::get_cms_participant();
        if (!$cms || $cms->get_mid() != $mid || $cms->get_ecs_id() != $ecsid) {
            log::add("Warning: received update directory ({$resourceid}) event from non-CMS participant");
            return;
        }

        // Get the details of the existing directories / trees in Moodle.
        $existingtreesdb = $DB->get_records('local_campusconnect_dirroot', array(
            'resourceid' => $resourceid,
            'ecsid' => $ecsid, 'mid' => $mid
        ));
        $existingdirsdb = $DB->get_records('local_campusconnect_dir', array('resourceid' => $resourceid));
        /** @var directorytree[] $existingtrees */
        $existingtrees = array();
        /** @var directory[] $existingdirs */
        $existingdirs = array();
        foreach ($existingtreesdb as $existingtreedb) {
            $existingtrees[$existingtreedb->rootid] = new directorytree($existingtreedb);
        }
        foreach ($existingdirsdb as $existingdirdb) {
            $existingdirs[$existingdirdb->directoryid] = new directory($existingdirdb);
        }
        if (is_array($directories)) {
            $directories = reset($directories);
        }
        self::convert_from_old_schema($directories);
        unset($existingtreesdb, $existingdirsdb);

        directory::clear_directory_cache();

        // Loop through all the directories / trees in this resource and match them up with the existing directories in Moodle.
        foreach ($directories->nodes as $directory) {
            $isdirectorytree = $directory->parent->id ? false : true;
            if ($isdirectorytree) {
                if (!isset($existingtrees[$directories->rootID])) {
                    throw new coding_exception("delete_missing_directories - found a directory tree {$directories->rootID}".
                                               " in the resource that does not exist in Moodle (after doing the update)");
                }
                $existingtrees[$directories->rootID]->set_still_exists();
            } else {
                if (!isset($existingdirs[$directory->id])) {
                    throw new coding_exception("delete_missing_directories - found a directory {$directories->id} in the".
                                               " resource that does not exist in Moodle (after doing the update)");
                }
                $existingdirs[$directory->id]->set_still_exists();
            }
        }

        // Delete any trees / directories no longer found in this resource.
        foreach ($existingtrees as $existingtree) {
            if (!$existingtree->still_exists()) {
                $existingtree->delete();
            }
        }
        foreach ($existingdirs as $existingdir) {
            if (!$existingdir->still_exists()) {
                $existingdir->delete();
            }
        }
    }

    public static function check_all_mappings() {
        global $DB;

        // Check all (non-deleted) directory tree mappings.
        $categoryids = array();
        $trees = $DB->get_records_select('local_campusconnect_dirroot', 'mappingmode <> ?', array(self::MODE_DELETED));
        /** @var $dirtrees directorytree[] */
        $dirtrees = array();
        foreach ($trees as $tree) {
            $dirtree = new directorytree($tree);
            if ($catid = $dirtree->get_category_id()) {
                $dirtrees[] = $dirtree;
                $categoryids[] = $catid;
            }
        }
        $categories = $DB->get_records_list('course_categories', 'id', $categoryids, 'id', 'id');
        foreach ($dirtrees as $tree) {
            if ($tree->get_category_id()) {
                if (!array_key_exists($tree->get_category_id(), $categories)) {
                    // Looks like the category has been deleted - clear the mapping.
                    $tree->unmap_category();
                }
            }
        }

        // Check all directory mappings.
        $dbdirs = $DB->get_records('local_campusconnect_dir');
        /** @var $dirs directory[] */
        $dirs = array();
        $categoryids = array();
        foreach ($dbdirs as $dbdir) {
            $dir = new directory($dbdir);
            if ($catid = $dir->get_category_id()) {
                $dirs[] = $dir;
                $categoryids[] = $catid;
            }
        }
        $categories = $DB->get_records_list('course_categories', 'id', $categoryids, 'id', 'id, sortorder');
        /** @var $recreate directory[] */
        $recreate = array();
        foreach ($dirs as $dir) {
            if ($dir->get_category_id()) {
                if (!array_key_exists($dir->get_category_id(), $categories)) {
                    // Directory was mapped onto a category that no longer exists.
                    if ($dir->clear_deleted_category()) {
                        $recreate[] = $dir;
                    }
                }
            }
        }
        // Try to recreate the categories for any automatically mapped directories that
        // previously had categories.
        $fixorder = false;
        if ($recreate) {
            foreach ($recreate as $dir) {
                $dirtree = $dir->get_directory_tree();
                $dir->create_category($dirtree->get_category_id(), false);
            }
            $fixorder = true;
        }

        // Update the sort order for all categories, if selected.
        foreach ($dirtrees as $dirtree) {
            if ($dirtree->should_take_over_position()) {
                $changes = directory::sort_categories($dirtree->rootid, $dirs, $categories);
                $fixorder = $fixorder || $changes;
            }
            if ($dirtree->should_take_over_allocation()) {
                $changes = course::sort_courses($dirtree->rootid);
                $fixorder = $fixorder || $changes;
            }
        }

        if ($fixorder) {
            fix_course_sortorder();
        }
    }

    /**
     * Returns the category that a given directory is mapped on to, creating the category if required and
     * fixing the mapping in place if it is a provisional mapping.
     * @param integer $directoryid the ID of the directory on the ECS
     * @return mixed integer | null the ID of the Moodle category to create the course in - null if mapping not available
     */
    public static function get_category_for_course($directoryid) {
        global $DB;

        $sql = "SELECT dr.*
                  FROM {local_campusconnect_dirroot} dr
                  JOIN {local_campusconnect_dir} d ON d.rootid = dr.rootid
                 WHERE d.directoryid = :directoryid";
        $params = array('directoryid' => $directoryid);
        if (!$dirtreedata = $DB->get_record_sql($sql, $params)) {
            throw new directorytree_exception("Attempting to find category for non-existent directory $directoryid");
        }

        $dirtree = new directorytree($dirtreedata);
        /** @var $dir directory */
        $dir = $dirtree->get_directory($directoryid);
        return $dir->create_category($dirtree->get_category_id());
    }
}
