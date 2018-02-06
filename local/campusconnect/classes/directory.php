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
use coursecat;
use html_writer;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class directory {

    const MAPPING_AUTOMATIC = 0;
    const MAPPING_MANUAL_PENDING = 1; // No courses within it yet.
    const MAPPING_MANUAL = 3; // Courses now exist within it.
    const MAPPING_DELETED = 2;

    const STATUS_PENDING_UNMAPPED = 1000;
    const STATUS_PENDING_MANUAL = 1001;
    const STATUS_PENDING_AUTOMATIC = 1002;
    const STATUS_MAPPED_MANUAL = 1003;
    const STATUS_MAPPED_AUTOMATIC = 1004;
    const STATUS_DELETED = 1005;

    protected $recordid = null;
    protected $resourceid = null;
    /** @var $rootid int */
    protected $rootid = null;
    protected $directoryid = null;
    protected $title = null;
    protected $parentid = null;
    protected $sortorder = null;
    protected $categoryid = null;
    protected $mapping = self::MAPPING_AUTOMATIC;

    protected $stillexists = false; // Flag used during updates from ECS.
    protected $parent = null;

    protected static $dbfields = array(
        'resourceid', 'rootid', 'directoryid', 'title', 'parentid', 'sortorder', 'categoryid', 'mapping'
    );

    protected static $dirs = array();
    /** @var directory[] $newdirs */
    protected static $newdirs = array();

    /**
     * Create a directory instance
     * @param object $data optional - the record loaded from the database
     */
    public function __construct($data = null) {
        if ($data) {
            $this->set_data($data);
        }
    }

    protected function set_data($data) {
        $this->recordid = $data->id;
        foreach (self::$dbfields as $field) {
            if (isset($data->$field)) {
                $this->$field = $data->$field;
            }
        }
    }

    public function get_root_id() {
        return $this->rootid;
    }

    public function get_directory_id() {
        return $this->directoryid;
    }

    public function get_title() {
        return $this->title;
    }

    public function get_category_id() {
        return $this->categoryid;
    }

    public function get_directory_tree() {
        return directorytree::get_by_root_id($this->rootid);
    }

    public function can_unmap() {
        // Only pending-manual mappings can be remapped if the category id exists.
        return ($this->mapping == self::MAPPING_MANUAL_PENDING);
    }

    public function can_map() {
        // Can only map if not already automatically mapped.
        if ($this->categoryid) {
            return ($this->mapping != self::MAPPING_AUTOMATIC);
        }
        return true;
    }

    /**
     * Get the parent directory
     * @return mixed directory | null (if the parent is the root directory)
     */
    public function get_parent() {
        if (!$this->parentid) {
            throw new coding_exception("get_parent - all directories must have a parentid (directoryid: {$this->directoryid})");
        }
        if ($this->parentid == $this->rootid) {
            return null;
        }
        if ($this->parent != null) {
            return $this->parent;
        }
        /** @var $dirs directory[] */
        $dirs = self::get_directories($this->rootid);
        foreach ($dirs as $dir) {
            if ($dir->get_directory_id() == $this->parentid) {
                $this->parent = $dir;
                return $this->parent;
            }
        }
        throw new coding_exception("get_parent - parent {$this->parentid} not found for directory {$this->directoryid}");
    }

    /**
     * Get the child directories below this one
     * @return directory[]
     */
    public function get_children() {
        $children = array();
        $dirs = self::get_directories($this->rootid);
        foreach ($dirs as $dir) {
            if ($dir->parentid == $this->directoryid) {
                $children[] = $dir;
            }
        }
        return $children;
    }

    public function check_categoryid_mapped_by_child($categoryid) {
        if ($this->categoryid == $categoryid) {
            return true;
        }
        foreach ($this->get_children() as $child) {
            if ($child->check_categoryid_mapped_by_child($categoryid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build an unordered list element for this directory and it's children
     * @param string $radioname
     * @param int $selecteddir
     * @return string HTML fragment
     */
    public function output_directory_tree_node($radioname, $selecteddir = null) {
        static $classes = array(
            self::STATUS_PENDING_UNMAPPED => 'status_pending_unmapped',
            self::STATUS_PENDING_AUTOMATIC => 'status_pending_automatic',
            self::STATUS_PENDING_MANUAL => 'status_pending_manual',
            self::STATUS_MAPPED_MANUAL => 'status_mapped_manual',
            self::STATUS_MAPPED_AUTOMATIC => 'status_mapped_automatic',
            self::STATUS_DELETED => 'status_deleted'
        );

        $expand = false;
        $childnodes = '';
        if ($children = $this->get_children()) {
            foreach ($children as $child) {
                list($childnode, $childexpand) = $child->output_directory_tree_node($radioname, $selecteddir);
                $childnodes .= $childnode;
                $expand = $expand || $childexpand;
            }
            $childnodes = html_writer::tag('ul', $childnodes);
        }
        $status = $this->get_status();
        $class = $classes[$status];
        $ret = html_writer::tag('span', s($this->title), array('class' => $class));
        if ($radioname) {
            $elid = $radioname.'-'.$this->directoryid;
            $label = html_writer::tag('label', $ret, array('for' => $elid));
            $radioparams = array(
                'type' => 'radio',
                'name' => $radioname,
                'id' => $elid,
                'class' => 'directoryradio',
                'value' => $this->directoryid
            );
            if ($selecteddir == $this->directoryid) {
                $radioparams['checked'] = 'checked';
                $expand = true;
            }
            if ($status == self::STATUS_MAPPED_AUTOMATIC ||
                $status == self::STATUS_DELETED ||
                $status == self::STATUS_PENDING_AUTOMATIC
            ) {
                $radioparams['disabled'] = 'disabled';
            } else if ($status == self::STATUS_MAPPED_MANUAL ||
                $status == self::STATUS_PENDING_MANUAL
            ) {
                $expand = true;
            }
            $ret = html_writer::empty_tag('input', $radioparams);
            $ret .= ' '.$label;
            $ret = html_writer::tag('span', $ret); // To stop YUI treeview getting upset.
        }
        $ret .= $childnodes;
        if ($expand) {
            $params = array('class' => 'expanded');
        } else {
            $params = array();
        }
        return array(html_writer::tag('li', $ret, $params), $expand);
    }

    /**
     * Calculate the current status of the directory
     * @return int status - see directory::STATUS_* for possible values
     */
    public function get_status() {
        if ($this->mapping == self::MAPPING_DELETED) {
            return self::STATUS_DELETED;
        }

        if ($this->mapping == self::MAPPING_MANUAL) {
            return self::STATUS_MAPPED_MANUAL;
        }

        if ($this->mapping == self::MAPPING_MANUAL_PENDING) {
            return self::STATUS_PENDING_MANUAL;
        }

        if (!$parent = $this->get_parent()) {
            return self::STATUS_PENDING_UNMAPPED;
        }

        $parentstatus = $parent->get_status();
        if ($parentstatus == self::STATUS_PENDING_UNMAPPED) {
            return self::STATUS_PENDING_UNMAPPED;
        }

        if ($this->categoryid) {
            return self::STATUS_MAPPED_AUTOMATIC;
        } else {
            return self::STATUS_PENDING_AUTOMATIC;
        }
    }

    /**
     * Used during ECS updates to spot directories that are no longer on the ECS
     * @return bool - true if updated during last ECS update
     */
    public function still_exists() {
        return $this->stillexists;
    }

    /**
     * Check the parentid from the ECS matches the parentid the directory already has - throws
     * exception if they do not match.
     * @param int $parentid (from ECS)
     */
    public function check_parent_id($parentid) {
        if ($this->parentid != $parentid) {
            throw new directorytree_exception("parent {$this->parentid} for directory {$this->directoryid}".
                                              " does not match parent id {$parentid} from ECS");
        }
    }

    /**
     * Create a new directory record
     * @param int $resourceid - id of the resource on the ECS
     * @param int $rootid
     * @param int $directoryid
     * @param int $parentid
     * @param string $title
     * @param int $sortorder
     */
    public function create($resourceid, $rootid, $directoryid, $parentid, $title, $sortorder) {
        global $DB;

        $ins = new stdClass();
        $ins->resourceid = $resourceid;
        $ins->rootid = $rootid;
        $ins->directoryid = $directoryid;
        $ins->title = $title;
        $ins->parentid = $parentid;
        $ins->sortorder = $sortorder;
        $ins->categoryid = null;
        $ins->mapping = self::MAPPING_AUTOMATIC;
        $ins->id = $DB->insert_record('local_campusconnect_dir', $ins);

        $this->set_data($ins);
        self::add_to_dirs($this->rootid, $this->recordid, $this);
    }

    public function delete() {
        $this->set_field('mapping', self::MAPPING_DELETED);
    }

    protected function set_field($field, $value) {
        global $DB;

        $DB->set_field('local_campusconnect_dir', $field, $value, array('id' => $this->recordid));
        $this->$field = $value;
    }

    public function set_title($title) {
        global $DB;

        if ($title == $this->title) {
            return; // No update needed.
        }

        $this->set_field('title', $title);
        if ($this->categoryid) {
            $DB->set_field('course_categories', 'name', $this->title, array('id' => $this->categoryid));
        }
    }

    public function set_order($sortorder) {
        if ($sortorder == $this->sortorder) {
            return; // No update needed.
        }

        $this->set_field('sortorder', $sortorder);

        // Sortorder is automatically checked as part of the cron process, so any category moving will happen then.
    }

    /**
     * Mark as still existing on the ECS server, after the current update
     */
    public function set_still_exists() {
        $this->stillexists = true;
        if ($this->mapping == self::MAPPING_DELETED) {
            // Should not be the case, but resurrect the directory by setting the mapping to automatic.
            $this->set_field('mapping', self::MAPPING_AUTOMATIC);
        }
    }

    /**
     * Map this directory onto a course category
     * @param int $categoryid
     * @return null|string - error message to display
     */
    public function map_category($categoryid) {
        global $DB;

        if (!$this->can_map()) {
            throw new directorytree_exception("Cannot map directory {$this->directoryid} as it is already mapped automatically");
        }

        if ($this->categoryid == $categoryid) {
            return null; // No change.
        }

        if (!$newcategory = $DB->get_record('course_categories', array('id' => $categoryid))) {
            throw new coding_exception("Directory tree - attempting to map onto non-existent category $categoryid");
        }

        if ($this->categoryid) {
            if ($this->check_categoryid_mapped_by_child($categoryid)) {
                return get_string('cannotmapsubcategory', 'local_campusconnect');
            }
        }

        $oldcategoryid = $this->categoryid;
        $this->set_field('categoryid', $categoryid);

        if ($this->mapping == self::MAPPING_AUTOMATIC) {
            $this->set_field('mapping', self::MAPPING_MANUAL_PENDING);
        }

        if ($oldcategoryid) {
            // Need to move all contained courses & directories.
            self::move_category($this->directoryid, $oldcategoryid, $categoryid);
        } else {
            if (directorytree::should_create_empty_categories()) {
                $tree = $this->get_directory_tree();
                $tree->create_all_categories();
            }
        }

        return null;
    }

    /**
     * Unmap this directory from the category.
     */
    public function unmap_category() {
        if (!$this->can_unmap()) {
            throw new directorytree_exception("Unmapping of directories can only be done when mapping is pending -".
                                              " current mapping status: {$this->mapping}");
        }

        $this->set_field('categoryid', null);
        $this->set_field('mapping', self::MAPPING_AUTOMATIC);
    }

    /**
     * The category this directory is mapped on to no longer exists - find the
     * most appropriate fix, based on the mapping status.
     * @return bool - true if should attempt to recreate
     */
    public function clear_deleted_category() {
        if (!$this->categoryid) {
            return false;
        }
        if ($this->mapping == self::MAPPING_DELETED) {
            return false;
        }

        $this->set_field('categoryid', null);

        if ($this->mapping == self::MAPPING_AUTOMATIC) {
            return true;
        }

        $this->set_field('mapping', self::MAPPING_AUTOMATIC);

        return false;
    }

    /**
     * Create a category for the selected directory, along with any parent categories
     * that do not already exist.
     * @param int $rootcategoryid - ID of the category that the root directory is mapped on to
     * @param bool $fixsortorder optional - used to make sure fix_course_sortorder is only called once
     * @return int $id of the category created (or already allocated)
     */
    public function create_category($rootcategoryid, $fixsortorder = true) {
        global $DB;

        if ($this->categoryid) {
            // Directory already has an associated category - return it.
            if ($this->mapping == self::MAPPING_MANUAL_PENDING) {
                // Time to fix this mapping in place.
                $this->set_field('mapping', self::MAPPING_MANUAL);
            }
            return $this->categoryid;
        }

        if ($this->parentid == $this->rootid) {
            // Reached the directory tree root - return the mapped category.
            $parentcat = $rootcategoryid;
        } else {
            // Make sure the parent category has been created.
            $parent = $this->get_parent();
            $parentcat = $parent->create_category($rootcategoryid, false);
        }

        if (!$parentcat) {
            return null; // Will happen if the root node is unmapped.
        }

        // Create a new category for this directory.
        $ins = new stdClass();
        $ins->parent = $parentcat;
        $ins->name = $this->title;
        $ins->sortorder = 999; // TODO - do something with the order field.
        $categoryid = $DB->insert_record('course_categories', $ins);
        $this->set_field('categoryid', $categoryid);

        if ($fixsortorder) {
            // Only do once - on the outer level of the loop.
            fix_course_sortorder();
        }

        return $this->categoryid;
    }

    /**
     * Update the directory details from the ECS
     * @param int $resourceid
     * @param object $directory the details, direct from the ECS
     * @param int $rootid the id of the root of the directory tree
     * @return mixed directory | false : returns the directory,
     *                 if a new directory was created, false if it already existed
     */
    public static function check_update_directory($resourceid, $directory, $rootid) {
        $dirs = self::get_directories($rootid);
        foreach ($dirs as $dir) {
            if ($dir->get_directory_id() == $directory->id) {
                // Found directory - update it (if needed).
                $dir->check_parent_id($directory->parent->id);
                $dir->set_title($directory->title);
                if (isset($directory->order)) {
                    $dir->set_order($directory->order);
                }
                $dir->set_still_exists();
                return false;
            }
        }

        // Not found - create it.
        $order = isset($directory->order) ? $directory->order : null;
        $dir = new directory();
        $dir->create($resourceid, $rootid, $directory->id, $directory->parent->id, $directory->title, $order);
        $dir->set_still_exists();
        return $dir;
    }

    /**
     * Called after all calls to 'check_update_directory', to remove
     * any directories not listed on the ECS
     * @param int $rootid
     */
    public static function remove_missing_directories($rootid) {
        $dirs = self::get_directories($rootid);
        foreach ($dirs as $dir) {
            if (!$dir->still_exists()) {
                $dir->delete();
            }
        }
    }

    /**
     * Delete all directory mappings (but not the categories / courses they
     * are mapped on to)
     * @param int $rootid the directory tree being deleted
     */
    public static function delete_root_directory($rootid) {
        //global $DB;
        //$DB->delete_records('local_campusconnect_dir', array('rootid' => $rootid));

        /** @var $dirs directory[] */
        $dirs = self::get_directories($rootid);
        foreach ($dirs as $dir) {
            $dir->delete();
        }
    }

    /**
     * Get all the directories within the given directory tree
     * @param int $rootid
     * @return directory[]
     */
    public static function get_directories($rootid) {
        global $DB;
        if (!isset(self::$dirs[$rootid])) {
            $dirs = $DB->get_records('local_campusconnect_dir', array('rootid' => $rootid), 'id');
            self::$dirs[$rootid] = array_map(function ($data) {
                return new directory($data);
            }, $dirs);
        }
        return self::$dirs[$rootid];
    }

    public static function clear_directory_cache() {
        self::$dirs = array();
    }

    /**
     * Get the first level of directories for the given directory tree
     * @param int $rootid
     * @return directory[]
     */
    public static function get_toplevel_directories($rootid) {
        $dirs = self::get_directories($rootid);
        $tldirs = array();
        foreach ($dirs as $dir) {
            if ($dir->parentid == $rootid) {
                $tldirs[] = $dir;
            }
        }
        return $tldirs;
    }

    /**
     * Output the directory tree as nested unordered lists (ready for use with YUI treeview).
     * @param directorytree $dirtree
     * @param string $radioname - optional - if set, creates radio input elements for each item
     * @param null $selecteddir
     * @return string HTML of the lists
     */
    public static function output_directory_tree(directorytree $dirtree, $radioname, $selecteddir = null) {
        $expand = false;
        $childdirs = '';
        if ($dirs = self::get_toplevel_directories($dirtree->get_root_id())) {
            foreach ($dirs as $dir) {
                list($childdir, $childexpand) = $dir->output_directory_tree_node($radioname, $selecteddir);
                $childdirs .= $childdir;
                $expand = $expand || $childexpand;
            }
            $childdirs = html_writer::tag('ul', $childdirs);
        }
        $elid = $radioname.'-'.$dirtree->get_root_id();
        $label = html_writer::tag('label', s($dirtree->get_title()), array('for' => $elid));
        $radioparams = array(
            'type' => 'radio',
            'name' => $radioname,
            'id' => $elid,
            'class' => 'directoryradio',
            'value' => $dirtree->get_root_id()
        );
        if (is_null($selecteddir) || $dirtree->get_root_id() == $selecteddir) {
            $radioparams['checked'] = 'checked';
        }
        $ret = html_writer::empty_tag('input', $radioparams);
        $ret .= ' '.$label;
        $ret = html_writer::tag('span', $ret).$childdirs;
        if ($expand) {
            $params = array('class' => 'expanded');
        } else {
            $params = array();
        }
        return html_writer::tag('ul', html_writer::tag('li', $ret, $params));
    }

    public static function output_category_tree($radioname, $selectedcategory = null) {
        $ret = '';

        $basecat = coursecat::get(0);
        $cats = $basecat->get_children();
        foreach ($cats as $cat) {
            $ret .= self::output_category_and_children($cat, $radioname, $selectedcategory);
        }

        return html_writer::tag('ul', $ret);
    }

    /**
     * @param coursecat $category
     * @param $radioname
     * @param null $selectedcategory
     * @return string
     * @throws coding_exception
     */
    public static function output_category_and_children($category, $radioname, $selectedcategory = null) {
        $childcats = '';
        $cats = $category->get_children();
        if ($cats) {
            foreach ($cats as $cat) {
                $childcats .= self::output_category_and_children($cat, $radioname, $selectedcategory);
            }
            $childcats = html_writer::tag('ul', $childcats);
        }
        $ret = format_string($category->name);
        $elid = $radioname.'-'.$category->id;
        $labelparams = array(
            'for' => $elid,
            'id' => 'label'.$elid,
            'class' => 'categorylabel'
        );
        $radioparams = array(
            'type' => 'radio',
            'name' => $radioname,
            'id' => $elid,
            'class' => 'categoryradio',
            'value' => $category->id
        );
        if ($selectedcategory == $category->id) {
            $radioparams['checked'] = 'checked';
            $labelparams['class'] .= ' mapped_category';
        }
        $label = html_writer::tag('label', $ret, $labelparams);
        $ret = html_writer::empty_tag('input', $radioparams);
        $ret .= ' '.$label;
        $ret .= $childcats;
        return html_writer::tag('li', $ret);
    }

    /**
     * Add a newly-created directory to the cached list of directories
     * @param int $rootid
     * @param int $recordid - db id for the directory
     * @param directory $directory - the directory
     */
    protected static function add_to_dirs($rootid, $recordid, directory $directory) {
        if (isset(self::$dirs[$rootid])) {
            self::$dirs[$rootid][$recordid] = $directory;
        }
        if (!isset(self::$newdirs[$rootid])) {
            self::$newdirs[$rootid] = array();
        }
        self::$newdirs[$rootid][$recordid] = $directory;
    }

    /**
     * Create any sub-categories of the given directory tree that do not already
     * exist
     * @param int $rootid the root node of the directory tree
     * @param int $rootcategoryid the id of the Moodle category for the root node
     */
    public static function create_all_categories($rootid, $rootcategoryid) {
        $dirs = self::get_directories($rootid);
        foreach ($dirs as $dir) {
            if ($dir->mapping == self::MAPPING_DELETED) {
                continue; // Ignore any deleted directories when creating categories.
            }
            if (!$dir->get_category_id()) {
                $dir->create_category($rootcategoryid, false);
            }
        }

        fix_course_sortorder();
    }

    /**
     * Move all the courses and sub-directories when the root node of a directory tree
     * has been re-mapped
     * @param int $directoryid the root node of the directory tree
     * @param int $oldcategoryid the previous root category (for checking)
     * @param int $newcategoryid the new root category mapping
     */
    public static function move_category($directoryid, $oldcategoryid, $newcategoryid) {
        global $DB;

        // Find all directories at the top level of this tree that have not been manually mapped.
        $dirstomove = $DB->get_records('local_campusconnect_dir', array(
            'parentid' => $directoryid,
            'mapping' => self::MAPPING_AUTOMATIC
        ));

        // Move the category parents, as needed (checking the old parents were 'oldcategoryid').
        foreach ($dirstomove as $dirtomove) {
            if (!$dirtomove->categoryid) {
                continue; // Not yet mapped - nothing to do.
            }

            $category = $DB->get_record('course_categories', array('id' => $dirtomove->categoryid), 'id, parent', MUST_EXIST);
            if ($category->parent != $oldcategoryid) {
                throw new directorytree_exception("move_category: found automatic directory {$dirtomove->id} where category".
                                                  " parent != old category");
            }
            $category->parent = $newcategoryid;
            $DB->update_record('course_categories', $category);
        }

        // Move any courses within the root directory.
        $coursestomove = $DB->get_records('local_campusconnect_course', array('parentid' => $directoryid));
        foreach ($coursestomove as $coursetomove) {
            $course = $DB->get_record('course', array('id' => $coursetomove->id), 'id, category', MUST_EXIST);
            if ($course->category != $oldcategoryid) {
                throw new directorytree_exception("move_root_category: found course {$course->id} in root directory where".
                                                  " category != root directory category");
            }
            $course->category = $newcategoryid;
            $DB->update_record('course', $course);
        }

        // Tidy up the sort order, course count and category path fields.
        fix_course_sortorder();
    }

    /**
     * Check through the newly-created directories, to see if any matching categories
     * also need creating
     */
    public static function process_new_directories() {
        if (!self::$newdirs) {
            return;
        }

        if (!directorytree::should_create_empty_categories()) {
            return;
        }

        $dirtrees = directorytree::list_directory_trees();
        foreach (self::$newdirs as $dirs) {
            /** @var $dir directory */
            foreach ($dirs as $dir) {
                $founddirtree = null;
                foreach ($dirtrees as $dirtree) {
                    if ($dirtree->get_root_id() == $dir->get_root_id()) {
                        $founddirtree = $dirtree;
                        break;
                    }
                }
                if (!$founddirtree) {
                    throw new directorytree_exception("Unable to find directory tree ".$dir->get_root_id()." for directory ".
                                                      $dir->get_directory_id());
                }
                $mode = $founddirtree->get_mode();
                $rootcategoryid = $founddirtree->get_category_id();
                $createcategory = ($mode == directorytree::MODE_MANUAL);
                $createcategory = $createcategory || ($mode == directorytree::MODE_WHOLE && $rootcategoryid);
                if ($createcategory) {
                    $dir->create_category($rootcategoryid, false);
                }
            }
        }
        self::$newdirs = array();
        fix_course_sortorder();
    }

    /**
     * Make sure that the sortorder of the categories matches the sort order of the directories.
     * @param int $rootid
     * @param directory[] $dirs
     * @param stdClass[] $categories
     * @return bool true if changes made
     */
    public static function sort_categories($rootid, $dirs, $categories) {
        global $DB;
        $updated = false;
        $sorteddirs = array();
        foreach ($dirs as $dir) {
            if ($dir->rootid != $rootid) {
                continue;
            }
            if ($dir->get_status() != self::STATUS_MAPPED_AUTOMATIC) {
                continue; // Only automatically mapped categories should be sorted.
            }
            if (!isset($sorteddirs[$dir->parentid])) {
                $sorteddirs[$dir->parentid] = array();
            }
            $sortorder = $dir->sortorder;
            while (isset($sorteddirs[$dir->parentid][$sortorder])) {
                // Already a dir with the same parent and sortorder - adjust the sortorder until we avoid the conflict.
                $sortorder++;
            }
            if ($sortorder != $dir->sortorder) {
                $dir->set_order($sortorder); // Save the updated sortorder.
            }
            $sorteddirs[$dir->parentid][$sortorder] = $dir;
        }
        foreach ($sorteddirs as $sdirs) {
            /** @var $sdirs directory[] */
            ksort($sdirs);
            $lastsort = -1;
            foreach ($sdirs as $dir) {
                $catid = $dir->get_category_id();
                if (!$catid) {
                    continue;
                }
                if (!isset($categories[$catid])) {
                    continue; // Not sure this should happen, but will skip for now and hope it is fixed on the next cron.
                }
                $catsort = $categories[$catid]->sortorder;
                if ($catsort <= $lastsort) {
                    $catsort = $lastsort + 1;
                    $DB->set_field('course_categories', 'sortorder', $catsort, array('id' => $catid));
                    $updated = true;
                }
                $lastsort = $catsort;
            }
        }

        return $updated;
    }
}

