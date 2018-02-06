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
 * Front end for managing directory trees
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\directorytree;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/campusconnect/admin/directorytree_form.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/directorytree.php'));

admin_externalpage_setup('campusconnectdirectorymapping');

$form = new campusconnect_directorytree_form();
if ($form->is_cancelled()) {
    redirect($PAGE->url); // Will clear the settings back to their previous values.
}
if ($data = $form->get_data()) {
    directorytree::set_enabled($data->enabled);
    directorytree::set_create_empty_categories($data->createemptycategories);
    redirect($PAGE->url); // To remove the POST params from the page load.
}

$trees = directorytree::list_directory_trees();
$table = new html_table();
$table->head = array(
    get_string('treename', 'local_campusconnect'),
    get_string('treestatus', 'local_campusconnect')
);
$table->size = array(
    '60%',
    ''
);
$table->attributes = array('style' => 'width: 90%;');
$table->data = array();

$statuses = array(
    directorytree::MODE_PENDING => get_string('modepending', 'local_campusconnect'),
    directorytree::MODE_WHOLE => get_string('modewhole', 'local_campusconnect'),
    directorytree::MODE_MANUAL => get_string('modemanual', 'local_campusconnect'),
    directorytree::MODE_DELETED => get_string('modedeleted', 'local_campusconnect')
);
$baseediturl = new moodle_url('/local/campusconnect/admin/directorymapping.php');
foreach ($trees as $tree) {
    $editurl = new moodle_url($baseediturl, array('id' => $tree->get_root_id()));
    $editlink = html_writer::link($editurl, s($tree->get_title()));
    $status = $statuses[$tree->get_mode()];

    $row = array($editlink, $status);
    $table->data[] = $row;
}

echo $OUTPUT->header();

$form->display();
echo $OUTPUT->heading(get_string('directorytrees', 'local_campusconnect'));
if ($trees) {
    echo html_writer::table($table);
} else {
    echo get_string('notrees', 'local_campusconnect');
}

echo $OUTPUT->footer();