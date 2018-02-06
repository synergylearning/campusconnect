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
 * Front end for controlling the automatic filtering of created courses into subdirectories
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\course;
use local_campusconnect\filtering;
use local_campusconnect\metadata;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/campusconnect/admin/coursefiltering_form.php');

$categoryid = optional_param('categoryid', null, PARAM_INT);

$url = new moodle_url('/local/campusconnect/admin/coursefiltering.php', array());
if (!is_null($categoryid)) {
    $url->param('categoryid', $categoryid);
}

admin_externalpage_setup('campusconnectcoursefiltering');

$PAGE->set_url($url);

// Process the general settings form.
$globalsettings = filtering::load_global_settings();
$globalsettings['courseenabled'] = course::enabled();
$attributescount = max(count($globalsettings['attributes']), 3);
$custom = array(
    'attributes' => metadata::list_remote_fields(false),
    'attributescount' => $attributescount
);
foreach ($globalsettings['attributes'] as $key => $value) {
    $attribname = "attributes[{$key}]";
    $globalsettings[$attribname] = $value;
}
$form = new campusconnect_coursefiltering_form(null, $custom);
$form->set_data($globalsettings);

if ($form->is_cancelled()) {
    redirect($PAGE->url); // Will clear the settings back to their previous values.
}
if ($data = $form->get_data()) {
    foreach ($data->attributes as $key => $val) {
        if ($val == -1) {
            unset($data->attributes[$key]);
        }
    }
    filtering::save_global_settings($data);
    course::set_enabled($data->courseenabled);
    redirect($PAGE->url); // To remove the POST params from the page load.
}

// Set up the filtering form.
$catform = null;
$categorysettings = filtering::load_category_settings();
if (is_null($categoryid)) {
    if (!empty($categorysettings)) {
        $categoryid = array_keys($categorysettings);
        $categoryid = reset($categoryid); // Get the first categoryid.
    }
}
if (!is_null($categoryid)) {
    if (isset($categorysettings[$categoryid])) {
        $catdata = $categorysettings[$categoryid];
        $formdata = array();
        foreach ($catdata as $attribname => $attribsettings) {
            foreach ($attribsettings as $name => $val) {
                if ($name == 'words') {
                    $val = implode(',', $val);
                }
                $formdata["{$name}[$attribname]"] = $val;
            }
            $formdata["active[$attribname]"] = 1;
        }
    } else {
        $formdata = array();
    }
    $formdata['categoryid'] = $categoryid;

    $custom = array('attributes' => $globalsettings['attributes'], 'allsettings' => $categorysettings);
    $catform = new campusconnect_coursefilteringcategory_form(null, $custom);
    $catform->set_data($formdata);

    if ($catform->is_cancelled()) {
        redirect($PAGE->url); // Clear the settings to their previously saved values.
    }
    if ($data = $catform->get_data()) {
        // Save the category form data.
        $savedata = array();
        foreach ($globalsettings['attributes'] as $attribute) {
            if (empty($data->active[$attribute])) {
                continue; // Lave out unused attributes.
            }
            $settings = new stdClass();
            $settings->allwords = !empty($data->allwords[$attribute]);
            $settings->words = isset($data->words[$attribute]) ? explode(',', $data->words[$attribute]) : array();
            $settings->createsubdirectories = !empty($data->createsubdirectories[$attribute]);

            $savedata[$attribute] = $settings;
        }

        filtering::save_category_settings($categoryid, $savedata);
        redirect($PAGE->url); // To remove the POST params from the page load.
    }

    ob_start();
    $catform->display();
    $catform = ob_get_clean();
}

$baseurl = new moodle_url($PAGE->url);
$baseurl->remove_params('categoryid');
$baseurl->set_anchor('coursefiltering');
$cattree = filtering::output_category_tree($baseurl, array_keys($categorysettings), $categoryid);

$table = new html_table();
$table->head = array(
    get_string('localcategories', 'local_campusconnect'),
    get_string('filtersettings', 'local_campusconnect')
);
$table->size = array(
    '50%',
    ''
);
$table->attributes = array('style' => 'width: 90%;', 'class' => 'generaltable coursefiltertable');
$table->data = array(array($cattree, $catform));

// Output everything.
echo $OUTPUT->header();

$form->display();

echo html_writer::tag('a', '', array('name' => 'coursefiltering'));
echo $OUTPUT->heading(get_string('coursefiltering', 'local_campusconnect'));

echo html_writer::table($table);

echo $OUTPUT->footer();
