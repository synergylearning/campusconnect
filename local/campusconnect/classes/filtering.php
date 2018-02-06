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
 * Controls the filtering of incomming courses into the correct category(s)
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use coursecat;
use html_writer;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class filtering {

    public static $config = null;
    public static $globalsettings = array(
        'enabled' => 'bool', 'defaultcategory' => 'int', 'usesinglecategory' => 'bool',
        'singlecategory' => 'int', 'attributes' => 'array'
    );

    // -------------------------------------------
    // Using the course filtering
    // -------------------------------------------

    /**
     *
     * @param $coursedata
     * @param $ecssettings
     * @return array
     */
    public static function get_categories($coursedata, $ecssettings) {
        if (!self::enabled()) {
            throw new coding_exception("Must not call get_categories() when course import filtering is disabled.");
        }

        $categories = array();
        if ($createincategory = self::create_in_category()) {
            $categories[] = $createincategory;
        }

        $meta = new metadata($ecssettings, false);
        $metadata = $meta->flatten_remote_data($coursedata, false);
        foreach (self::load_category_settings() as $categoryid => $attributes) {
            if (self::check_filter_match($metadata, $attributes)) {
                $categories = array_merge($categories, self::find_or_create_categories($metadata, $attributes, $categoryid));
            }
        }

        if (empty($categories)) {
            $categories = array(self::get_default_category());
        }
        return $categories;
    }

    /**
     * Check if a particular filter is matched by this course metadata.
     *
     * @param array $coursedata the flattened metadata from the remote course
     * @param stdClass[] $attributes the filter settings for the attributes
     * @return bool true if the filter rule is matched
     */
    public static function check_filter_match($coursedata, $attributes) {
        foreach ($attributes as $attribute => $settings) {
            if (!$settings->allwords) { // Must match specific words.
                if (!isset($coursedata[$attribute])) {
                    return false; // Attribute does not exist in the metadata => does not match.
                }
                $val = $coursedata[$attribute];
                if (is_array($val)) {
                    $matches = array_intersect($val, $settings->words);
                    if (empty($matches)) {
                        return false; // None of the words match any of the values in the attribute.
                    }
                } else {
                    if (!in_array($val, $settings->words)) {
                        return false; // Attribute does not match the specified words.
                    }
                }
            } else if ($settings->createsubdirectories) {
                if (empty($coursedata[$attribute])) {
                    return false; // Attribute is needed to create subdirectories, but it has no value.
                }
            }
        }
        return true; // Filter matches on all attributes => create course here.
    }

    /**
     * Creates the tree of subcategories as specified by the attributes, if it does not already exists, and then
     * returns the ID of the category in which the course should be created.
     *
     * @param array $coursedata the flattened metadata from the remote course
     * @param stdClass[] $attributes the filter settings for the attributes
     * @param int $categoryid the base category for this filter
     * @return int[] the categoryids to create the course in
     */
    public static function find_or_create_categories($coursedata, $attributes, $categoryid) {
        global $DB;

        if (count($attributes) == 0) {
            return array($categoryid);
        }

        $attributekeys = array_keys($attributes);
        $attribute = array_shift($attributekeys);
        $settings = array_shift($attributes);

        if ($settings->createsubdirectories) {
            $catids = array();
            // Creating subcategories for this attribute.
            $catnames = $coursedata[$attribute];
            if (!is_array($catnames)) {
                $catnames = array($catnames);
            } else {
                if (!$settings->allwords) {
                    // Only create subcategories for matching words (if 'allwords' not selected).
                    $catnames = array_intersect($catnames, $settings->words);
                }
            }
            if (empty($catnames)) {
                throw new coding_exception("Attempting to create subdirectories for attribute '{$attribute}', but the".
                                           " course '{$coursedata['title']}' has no matching values for this attribute");
            }
            foreach ($catnames as $catname) {
                if (!$subcatid = $DB->get_field('course_categories', 'id', array('parent' => $categoryid, 'name' => $catname))) {
                    // Need to create a new subcategory.
                    $ins = new stdClass();
                    $ins->parent = $categoryid;
                    $ins->name = $catname;
                    $ins->sortorder = 999;
                    $newcat = coursecat::create($ins);
                    $subcatid = $newcat->id;
                }
                $catids = array_merge($catids, self::find_or_create_categories($coursedata, $attributes, $subcatid));
            }
        } else {
            // Not creating subcategories - carry on down the attributes.
            $catids = self::find_or_create_categories($coursedata, $attributes, $categoryid);
        }

        return $catids;
    }

    // -------------------------------------------
    // Global settings for course filtering
    // -------------------------------------------

    /**
     * Check if courses import filtering is enabled
     * @return bool
     */
    public static function enabled() {
        $config = self::get_config();
        if (isset($config->filteringenabled)) {
            return $config->filteringenabled;
        }
        return false;
    }

    /**
     * Returns the default category in which to create courses (if no other valid category is found)
     * @return int|bool - false if no category set
     */
    public static function get_default_category() {
        $config = self::get_config();
        if (isset($config->filteringdefaultcategory)) {
            return $config->filteringdefaultcategory;
        }
        return false;
    }

    /**
     * Returns the category in which all 'real' courses should be created (with internal links going in any other
     * categories)
     * @return int|bool - false if courses should not be created in a single category
     */
    public static function create_in_category() {
        $config = self::get_config();
        if (isset($config->filteringusesinglecategory) && $config->filteringusesinglecategory) {
            if (isset($config->filteringsinglecategory)) {
                return $config->filteringsinglecategory;
            }
        }
        return false;
    }

    /**
     * Returns an ordered list of the course attributes that are being used for filtering courses.
     * @return string[]
     */
    public static function course_attributes() {
        $config = self::get_config();
        if (isset($config->filteringattributes)) {
            return explode(',', $config->filteringattributes);
        }
        return array();
    }

    /**
     * Returns all the global settings as an array - suitable for use in the config form. See
     * \local_campusconnect\filtering::$globalsetting for a full list of the settings.
     * @return array
     */
    public static function load_global_settings() {
        $settings = array();
        $config = self::get_config();
        foreach (self::$globalsettings as $name => $type) {
            $configname = "filtering{$name}";
            if (isset($config->$configname)) {
                $val = $config->$configname;
                if ($type == 'array') {
                    $val = explode(',', $val);
                }
            } else {
                if ($type == 'array') {
                    $val = array();
                } else {
                    $val = false;
                }
            }
            $settings[$name] = $val;
        }
        return $settings;
    }

    /**
     * Saves all the global settings provided in the array. See \local_campusconnect\filtering::$globalsetting for
     * a full list of the available settings.
     * @param mixed $settings object|array
     * @throws coding_exception
     */
    public static function save_global_settings($settings) {
        $settings = (array)$settings;
        foreach (self::$globalsettings as $name => $type) {
            if (!isset($settings[$name])) {
                continue;
            }
            $val = $settings[$name];
            switch ($type) {
                case 'bool':
                    $val = $val ? 1 : 0;
                    break;
                case 'int':
                    $val = intval($val);
                    break;
                case 'array':
                    if (!is_array($val)) {
                        throw new coding_exception("Expected value '$name' to be an array");
                    }
                    array_map('trim', $val);
                    $val = implode(',', $val);
                    break;
            }
            set_config("filtering{$name}", $val, 'local_campusconnect');
        }
        self::$config = null; // Clear out the config cache.
    }

    // -------------------------------------------
    // Category settings for course filtering
    // -------------------------------------------

    /**
     * Load the filter settings for each of the Moodle categories. Settings are:
     * - allwords (bool) - whether the filter matches all words, or just specific words
     * - words (string[]) - a list of the words to match with (if enabled, above)
     * - createsubdirectories (bool) - whether or not to create subcategories named after the attribute values
     * @param int $categoryid optional - only load the settings for a specific category
     * @return array categoryid => array( attributename => settings )
     */
    public static function load_category_settings($categoryid = null) {
        global $DB;

        // Get all the course filter settings and store by categoryid.
        $ordered = array();
        $params = array();
        if (!is_null($categoryid)) {
            $params['categoryid'] = $categoryid;
        }
        $settings = $DB->get_records('local_campusconnect_filter', $params);;
        foreach ($settings as $setting) {
            if (!isset($ordered[$setting->categoryid])) {
                $ordered[$setting->categoryid] = array();
            }
            $setting->allwords = empty($setting->words);
            if ($setting->allwords) {
                $setting->words = array();
            } else {
                $setting->words = explode(',', $setting->words);
            }
            $ordered[$setting->categoryid][$setting->attribute] = $setting;
        }

        // Make sure only valid attributes are listed and they are in the correct order.
        $ret = array();
        $validattribs = self::course_attributes();
        foreach ($ordered as $catid => $attribs) {
            foreach ($validattribs as $validattrib) {
                if (isset($attribs[$validattrib])) {
                    if (!isset($ret[$catid])) {
                        $ret[$catid] = array();
                    }
                    $ret[$catid][$validattrib] = $attribs[$validattrib];
                } else {
                    //continue 2; // Once a valid attribute is missing, skip all the rest.
                }
            }
        }
        if ($categoryid) {
            return isset($ret[$categoryid]) ? $ret[$categoryid] : array();
        }
        return $ret;
    }

    /**
     * Save all the settings for a given category
     * @param int $categoryid
     * @param stdClass[] $settings - attributename => settings (see @load_category_settings for a list of settings)
     */
    public static function save_category_settings($categoryid, $settings) {
        global $DB;
        $oldsettings = self::load_category_settings($categoryid);
        // Check for any attributes that are no longer active.
        foreach ($oldsettings as $attribute => $oldsetting) {
            if (!isset($settings[$attribute])) {
                $DB->delete_records('local_campusconnect_filter', array('id' => $oldsetting->id));
                unset($oldsettings[$attribute]);
            }
        }
        // Check for any attributes that have been updated.
        foreach ($settings as $attribute => $setting) {
            $upd = new stdClass();
            if (!empty($setting->allwords)) {
                $upd->words = array();
            } else {
                if (!isset($setting->words)) {
                    throw new coding_exception("Required setting 'words' missing from settings for '{$attribute}'");
                }
                if (!is_array($setting->words)) {
                    throw new coding_exception("Setting 'words' is not an array in settings for '{$attribute}'");
                }
                $upd->words = array_map('trim', $setting->words);
            }
            $upd->words = implode(',', $upd->words);
            if (!isset($setting->createsubdirectories)) {
                throw new coding_exception("Required setting 'createsubdirectories' missing from settings for '{$attribute}'");
            }
            $upd->createsubdirectories = $setting->createsubdirectories;
            if (isset($oldsettings[$attribute])) {
                $oldattribute = $oldsettings[$attribute];
                $changed = ($oldattribute->createsubdirectories != $upd->createsubdirectories);
                $changed = $changed || (implode(',', $oldattribute->words) != $upd->words);
                if (!$changed) {
                    continue;
                }
                $upd->id = $oldattribute->id;
                $DB->update_record('local_campusconnect_filter', $upd);
            } else {
                $upd->attribute = $attribute;
                $upd->categoryid = $categoryid;
                $upd->id = $DB->insert_record('local_campusconnect_filter', $upd);
            }
        }
    }

    /**
     * Generate a list of all the categories on the site, hyperlinked to the editing page.
     * @param moodle_url $baseurl url used to create the edit filter links
     * @param int[] $activecategories the categories that currently have settings
     * @param int $selectedcategory optional the selected category
     * @return string
     */
    public static function output_category_tree($baseurl, $activecategories, $selectedcategory = null) {
        $ret = '';
        $basecat = coursecat::get(0);
        $cats = $basecat->get_children();
        foreach ($cats as $cat) {
            $ret .= self::output_category_and_children($cat, $baseurl, $activecategories, $selectedcategory);
        }

        return html_writer::tag('ul', $ret, array('class' => 'filtering_categorylist'));
    }

    /**
     * @param $category
     * @param $baseurl
     * @param $activecategories
     * @param null $selectedcategory
     * @return string
     */
    protected static function output_category_and_children(coursecat $category, $baseurl, $activecategories,
                                                           $selectedcategory = null) {
        $childcats = '';
        $cats = $category->get_children();
        if ($cats) {
            foreach ($cats as $cat) {
                $childcats .= self::output_category_and_children($cat, $baseurl, $activecategories, $selectedcategory);
            }
            $childcats = html_writer::tag('ul', $childcats);
        }
        $name = format_string($category->name);
        if ($category->id == $selectedcategory) {
            $name .= ' ===&gt;';
            $ret = html_writer::tag('span', $name, array('class' => 'selectedcategory'));
        } else {
            $url = new moodle_url($baseurl, array('categoryid' => $category->id));
            $ret = html_writer::link($url, $name);
        }
        if (in_array($category->id, $activecategories)) {
            $ret = html_writer::tag('span', $ret, array('class' => 'activecategory'));
        }
        $ret .= $childcats;
        return html_writer::tag('li', $ret);
    }

    // -------------------------------------------
    // Internal functions
    // -------------------------------------------

    /**
     * Internal function to load all config settings
     * @return mixed|null
     */
    protected static function get_config() {
        if (is_null(self::$config)) {
            self::$config = get_config('local_campusconnect');
        }
        return self::$config;
    }

}