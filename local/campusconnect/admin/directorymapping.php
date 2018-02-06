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
 * Front end for mapping directories onto categories
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\directory;
use local_campusconnect\directorytree;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/campusconnect/admin/directorymapping_form.php');

$rootid = required_param('id', PARAM_ALPHANUMEXT);
$dirtree = directorytree::get_by_root_id($rootid);

$mapdirectory = optional_param('mapdirectory', false, PARAM_TEXT);
$unmapdirectory = optional_param('unmapdirectory', false, PARAM_TEXT);
$showmapping = optional_param('showmapping', false, PARAM_TEXT);
$categoryid = optional_param('category', null, PARAM_INT);
$directoryid = optional_param('directory', null, PARAM_ALPHANUMEXT);
$showdirectory = optional_param('showdirectory', null, PARAM_ALPHANUMEXT);

$url = new moodle_url('/local/campusconnect/admin/directorymapping.php', array('id' => $rootid));
if ($showmapping && $directoryid) {
    $url->param('showdirectory', $directoryid);
    redirect($url);

} else if ($showdirectory) {
    $url->param('showdirectory', $showdirectory);
}
admin_externalpage_setup('campusconnectdirectorymapping');

$PAGE->set_url($url);
$PAGE->navbar->add(s($dirtree->get_title()), $PAGE->url);

// Process the general settings form.
$form = new campusconnect_directorymapping_form(null, array('dirtree' => $dirtree));
if ($form->is_cancelled()) {
    redirect($PAGE->url); // Will clear the settings back to their previous values.
}
if ($data = $form->get_data()) {
    $dirtree->update_settings($data);
    redirect($PAGE->url); // To remove the POST params from the page load.
}

// Process the directory mapping.
$mappingerror = null;
if ($mapdirectory || $unmapdirectory) {
    require_sesskey();

    if (!$directoryid) {
        $mappingerror = get_string('nodirectoryselected', 'local_campusconnect');
    } else if ($mapdirectory && !$categoryid) {
        $mappingerror = get_string('nocategoryselected', 'local_campusconnect');
        $showdirectory = $directoryid;
    } else {
        if ($directoryid == $dirtree->get_root_id()) {
            // Root node selected.
            if ($mapdirectory) {
                // Map.
                $dirtree->map_category($categoryid);
            } else {
                // Unmap.
                $dirtree->unmap_category();
            }

        } else {
            // Directory selected.
            if (!$mapdir = $dirtree->get_directory($directoryid)) {
                throw new moodle_exception('invaliddirectory', 'local_campusconnect', '', $directoryid);
            }

            if ($mapdirectory) {
                // Map.
                $mode = $dirtree->get_mode();
                if ($mode == directorytree::MODE_PENDING ||
                    $mode == directorytree::MODE_WHOLE
                ) {

                    if (!optional_param('mappingconfirm', false, PARAM_BOOL)) {
                        $continue = new moodle_url($PAGE->url, array(
                            'sesskey' => sesskey(),
                            'category' => $categoryid,
                            'directory' => $directoryid,
                            'mapdirectory' => 1,
                            'mappingconfirm' => 1
                        ));
                        $cancel = $PAGE->url;

                        echo $OUTPUT->header();
                        echo $OUTPUT->confirm(get_string('manualmappingwarning', 'local_campusconnect'), $continue, $cancel);
                        echo $OUTPUT->footer();
                        die();
                    }
                }

                $mappingerror = $mapdir->map_category($categoryid);
                $dirtree->set_mode(directorytree::MODE_MANUAL);
            } else {
                // Unmap.
                $mapdir->unmap_category();
            }

            if (!$mappingerror) {
                $redir = $PAGE->url;
                $redir->param('showdirectory', $directoryid);
                redirect($redir);
            }
        }
    }
}

// Show the mapping that has been selected.
$selecteddir = $dirtree->get_root_id();
$selectedcat = $dirtree->get_category_id();
if ($showdirectory) {
    if ($showdirectory != $dirtree->get_root_id()) {
        if (!$showdir = $dirtree->get_directory($showdirectory)) {
            throw new moodle_exception('invaliddirectory', 'local_campusconnect', '', $directoryid);
        }

        $selecteddir = $showdirectory;
        $selectedcat = $showdir->get_category_id();
    }
}

// Initialise the page javascript.
$opts = array(
    'mappings' => $dirtree->list_all_mappings()
);
$jsmodule = array(
    'name' => 'campusconnect_directorymapping',
    'fullpath' => new moodle_url('/local/campusconnect/admin/directorymapping.js'),
    'strings' => array(
        array('mapdirectory', 'local_campusconnect'),
        array('remapdirectory', 'local_campusconnect')
    ),
    'requires' => array('node', 'event')
);
$PAGE->requires->js_init_call('M.campusconnect_directorymapping.init', array($opts), true, $jsmodule);

// Generate the category & directory trees.
$table = new html_table();
$table->head = array(
    get_string('localcategories', 'local_campusconnect'),
    get_string('cmsdirectories', 'local_campusconnect')
);
$table->size = array(
    '50%',
    ''
);
$table->attributes = array('style' => 'width: 90%;');

$categorytree = directory::output_category_tree('category', $selectedcat);
$categorytree = html_writer::tag('div', $categorytree, array('id' => 'campusconnect_categorytree'));
if ($dirtree = directory::output_directory_tree($dirtree, 'directory', $selecteddir)) {
    $dirtree = html_writer::tag('div', $dirtree, array('id' => 'campusconnect_dirtree'));
} else {
    $dirtree = get_string('nodirectories', 'local_campusconnect');
}
$row = array(
    $categorytree,
    $dirtree
);
$table->data = array($row);

$mappingurl = new moodle_url($PAGE->url, array('sesskey' => sesskey()));

// Output everything.
echo $OUTPUT->header();

$form->display();
echo $OUTPUT->heading(get_string('directorymapping', 'local_campusconnect'));

echo html_writer::start_tag('form', array(
    'method' => 'POST',
    'action' => $mappingurl->out_omit_querystring()
));
echo html_writer::input_hidden_params($mappingurl);
echo html_writer::empty_tag('input', array(
    'type' => 'submit',
    'name' => 'mapdirectory',
    'id' => 'mapdirectorybutton',
    'class' => 'submit',
    'value' => get_string('mapdirectory', 'local_campusconnect')
));
echo html_writer::empty_tag('input', array(
    'type' => 'submit',
    'name' => 'unmapdirectory',
    'id' => 'unmapdirectorybutton',
    'class' => 'submit',
    'value' => get_string('unmapdirectory', 'local_campusconnect')
));
echo html_writer::empty_tag('input', array(
    'type' => 'submit',
    'name' => 'showmapping',
    'id' => 'showmappingbutton',
    'class' => 'submit',
    'value' => get_string('showmapping', 'local_campusconnect')
));
if ($mappingerror) {
    echo ' '.$OUTPUT->error_text($mappingerror);
}
echo html_writer::table($table);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
