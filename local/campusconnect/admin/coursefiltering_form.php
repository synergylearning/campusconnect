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
 * Forms for the 'course filtering' page
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->libdir.'/formslib.php');

class campusconnect_coursefiltering_form extends moodleform {
    protected function definition() {
        global $CFG;

        $mform = $this->_form;

        $attributes = $this->_customdata['attributes'];
        $attributescount = $this->_customdata['attributescount'];

        require_once($CFG->libdir.'/coursecatlib.php');
        $categorylist = coursecat::make_categories_list();

        // Form elements.
        $mform->addElement('header', '', get_string('coursefilteringsettings', 'local_campusconnect'));
        $mform->addElement('selectyesno', 'courseenabled', get_string('courseenabled', 'local_campusconnect'));
        $mform->addElement('selectyesno', 'enabled', get_string('enablefiltering', 'local_campusconnect'));
        $mform->addElement('select', 'defaultcategory', get_string('defaultcategory', 'local_campusconnect'), $categorylist);
        $mform->addElement('selectyesno', 'usesinglecategory', get_string('usesinglecategory', 'local_campusconnect'));
        $mform->addElement('select', 'singlecategory', get_string('singlecategory', 'local_campusconnect'), $categorylist);

        $mform->addElement('header', '', get_string('courseattributes', 'local_campusconnect'));
        $attributes = array_combine($attributes, $attributes);
        $attributes = array(-1 => get_string('unused', 'local_campusconnect')) + $attributes;
        $repeatarray = array(
            $mform->createElement('select', 'attributes', get_string('filteringattribute', 'local_campusconnect'), $attributes)
        );
        $repeatopts = array('attributes' => array('default' => -1));
        $stradd = get_string('addattributes', 'local_campusconnect');
        $this->repeat_elements($repeatarray, $attributescount, $repeatopts, 'attributescount', 'add_attributes', 2, $stradd, true);

        $this->add_action_buttons(true, get_string('savegeneral', 'local_campusconnect'));

        // Help buttons.
        $mform->addHelpButton('usesinglecategory', 'usesinglecategory', 'local_campusconnect');

        // Disable all form elements if filtering is disabled.
        $mform->disabledIf('defaultcategory', 'enabled', 'eq', 0);
        $mform->disabledIf('usesinglecategory', 'enabled', 'eq', 0);
        $mform->disabledIf('singlecategory', 'usesinglecategory', 'eq', 0);
        $mform->disabledIf('attributes', 'enabled', 'eq', 0);
    }

    public function validation($data, $files) {
        // Check that each course attribute is only listed once.
        $errors = parent::validation($data, $files);
        $usedattrib = array();
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $idx => $value) {
                if ($value == -1) {
                    continue;
                }
                if (in_array($value, $usedattrib)) {
                    $errors["attributes[$idx]"] = get_string('attributesonce', 'local_campusconnect', $value);
                } else {
                    $usedattrib[] = $value;
                }
            }
        }
        if (!empty($data['enabled'])) {
            if (empty($data['defaultcategory'])) {
                $errors["defaultcategory"] = get_string('defaultcategoryrequired', 'local_campusconnect');
            }
        }
        return $errors;
    }
}

class campusconnect_coursefilteringcategory_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $attributes = $this->_customdata['attributes'];

        $mform->addElement('hidden', 'categoryid', 0);
        $mform->setType('categoryid', PARAM_INT);

        if (empty($attributes)) {
            $mform->addElement('static', '', '', get_string('noattributes', 'local_campusconnect'));
            return;
        }
        $struseattrib = get_string('useattribute', 'local_campusconnect');
        $strallwords = get_string('allwords', 'local_campusconnect');
        $strwords = get_string('filterwords', 'local_campusconnect');
        $strcreatesubdirectories = get_string('createsubdirectories', 'local_campusconnect');
        foreach ($attributes as $attribute) {
            $mform->addElement('header', '', $attribute);

            $mform->addElement('selectyesno', "active[$attribute]", $struseattrib);
            $mform->setDefault("active[$attribute]", 0);

            $mform->addElement('selectyesno', "allwords[$attribute]", $strallwords);
            $mform->setDefault("allwords[$attribute]", 1);
            $mform->disabledIf("allwords[$attribute]", "active[$attribute]", 'eq', 0);
            $mform->addHelpButton("allwords[$attribute]", 'allwords', 'local_campusconnect');

            $mform->addElement('text', "words[$attribute]", $strwords);
            $mform->disabledIf("words[$attribute]", "allwords[$attribute]", 'eq', 1);
            $mform->setType("words[$attribute]", PARAM_RAW);

            $mform->addElement('selectyesno', "createsubdirectories[$attribute]", $strcreatesubdirectories);
            $mform->disabledIf("createsubdirectories[$attribute]", "active[$attribute]", 'eq', 0);
            $mform->addHelpButton("createsubdirectories[$attribute]", 'createsubdirectories', 'local_campusconnect');
        }

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $allsettings = $this->_customdata['allsettings'];
        $attributes = $this->_customdata['attributes'];

        $useallwords = false;
        foreach ($attributes as $attribute) {
            if ($data['active'][$attribute]) {
                // Check if 'all words' has already been used higher up.
                if ($data['allwords'][$attribute]) {
                    $useallwords = $attribute;
                } else {
                    if ($useallwords) {
                        $errors["allwords[$attribute]"] = get_string('errorallwordsused', 'local_campusconnect', $useallwords);
                    }
                }
            }
            /*
            foreach ($allsettings as $categoryid => $attributesettings) {
                if ($categoryid == $data['categoryid']) {
                    continue;
                }
                if (!isset($attributesettings[$attribute])) {
                    continue;
                }
                if ($attributesettings[$attribute]->allwords) {
                    // Check if the 'all words' setting has already been used for this attribute in a different category.
                    if ($data['active'][$attribute]) {
                        $info = new stdClass();
                        $info->categoryname = $DB->get_field('course_categories', 'name', array('id' => $categoryid));
                        $info->attribute = $attribute;
                        $errors["active[$attribute]"] = get_string('errorallwordsusedcategory', 'local_campusconnect', $info);
                    }
                } else if (!empty($data['words'][$attribute])) {
                    // Check if any of the filter words have been used already in a different category.
                    $words = explode(',', $data['words'][$attribute]);
                    $duplicates = array_intersect($attributesettings[$attribute]->words, $words);
                    if (!empty($duplicates)) {
                        $info = new stdClass();
                        $info->categoryname = $DB->get_field('course_categories', 'name', array('id' => $categoryid));
                        $info->attribute = $attribute;
                        $info->words = implode(',', $duplicates);
                        $errors["words[$attribute]"] = get_string('errorwordsused', 'local_campusconnect', $info);
                    }
                }
            }
            */
        }

        return $errors;
    }
}