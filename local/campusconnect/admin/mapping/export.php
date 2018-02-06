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
 * Settings page for campus connect
 *
 * @package    admin_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\ecssettings;
use local_campusconnect\metadata;

defined('MOODLE_INTERNAL') || die();
global $CFG, $OUTPUT;
require_once("$CFG->libdir/formslib.php");

$mform = new campusconnect_export_form();

$redir = new moodle_url('/local/campusconnect/admin/datamapping.php', array('type' => 'export'));

$errors = array();
$ecslist = ecssettings::list_ecs();
if ($mform->is_cancelled()) {

    redirect($redir);

} else if ($post = $mform->get_data()) {

    $courselinkdata = array();
    $coursedata = array();
    foreach ($ecslist as $ecsid => $ecsname) {
        $courselinkdata[$ecsid] = array();
        $coursedata[$ecsid] = array();
        foreach (metadata::list_remote_fields(true) as $fieldname) {
            $fullfieldname = $ecsid.'_'.$fieldname.'_courselink';
            if (isset($post->{$fullfieldname})) {
                $courselinkdata[$ecsid][$fieldname] = $post->{$fullfieldname};
            }
        }
        /*
        // No exporting of courses, just course links.
        foreach (metadata::list_remote_fields(false) as $fieldname) {
            $fullfieldname = $ecsid.'_'.$fieldname.'_course';
            if (isset($post->{$fullfieldname})) {
                $coursedata[$ecsid][$fieldname] = $post->{$fullfieldname};
            }
        }
        */
    }

    foreach ($ecslist as $ecsid => $ecsname) {
        if (isset($coursedata[$ecsid]) || isset($courselinkdata[$ecsid])) {
            $ecssettings = new ecssettings($ecsid);
            /*
            // No exporting of courses, just course links.
            if (isset($coursedata[$ecsid])) {
                $metadata = new metadata($ecssettings, false);
                if (!$metadata->set_export_mappings($coursedata[$ecsid])) {
                    list ($errmsg, $errfield) = $metadata->get_last_error();
                    $errors[$ecsid.'_'.$errfield.'_course'] = $errmsg;
                }
            }
            */
            if (isset($courselinkdata[$ecsid])) {
                $metadata = new metadata($ecssettings, true);
                if (!$metadata->set_export_mappings($courselinkdata[$ecsid])) {
                    list ($errmsg, $errfield) = $metadata->get_last_error();
                    $errors[$ecsid.'_'.$errfield.'_courselink'] = $errmsg;
                }
            }

        }
    }

    if (empty($errors)) {
        redirect($redir);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

print '<div class="controls"><a href="?type=import">'.get_string('import', 'local_campusconnect').'</a> |
            <strong><a href="?type=export">'.get_string('export', 'local_campusconnect').'</a></strong></div>';

$remotefields = metadata::list_local_fields();
$helpcontent = '';
foreach ($remotefields as $remotefield) {
    $helpcontent .= '{'.$remotefield.'}<br />';
}
print "<div style='float: left; width: 45%; border: 1px solid #000; background: #ddd; margin: 10px 5px; padding: 5px;'><strong>"
    .get_string('courseavailablefields', 'local_campusconnect').':</strong><br />'.$helpcontent."</div>";

echo html_writer::empty_tag('br', array('class' => 'clearer'));

if (!empty($errors)) {
    $mform->set_errors($errors);
}

echo html_writer::start_tag('span', array('class' => 'campusconnect_metadata'));
$mform->display();
echo html_writer::end_tag('span');

class campusconnect_export_form extends moodleform {

    public function definition() {
        $ecslist = ecssettings::list_ecs();

        foreach ($ecslist as $ecsid => $ecsname) {

            $mform = $this->_form;

            $mform->addElement('hidden', 'type', 'export');
            $mform->setType('type', PARAM_ALPHA);

            $mform->addElement('header');
            $mform->addElement('html', "<h2>$ecsname</h2>");

            $strunmapped = get_string('unmapped', 'local_campusconnect');
            $strnomappings = get_string('nomappings', 'local_campusconnect');

            /*
            // No exporting of courses, just course links.
            $mform->addElement('html', "<h3>".get_string('course', 'local_campusconnect')."</h3>");

            $ecssettings = new ecssettings($ecsid);
            $metadata = new metadata($ecssettings, false);
            $remotefields = $metadata->list_remote_fields(false);
            $currentmappings = $metadata->get_export_mappings();

            foreach ($remotefields as $remotemap) {
                $elname = $ecsid.'_'.$remotemap.'_course';
                if ($remotemap == 'summary') {
                    $mform->addElement('editor', $elname, $remotemap);
                    $mform->setType($elname, PARAM_RAW);
                    $mform->setDefault($elname, array('text'=>$currentmappings[$remotemap], 'format'=>FORMAT_HTML));
                } else if ($metadata->is_remote_text_field($remotemap, false)) {
                    $mform->addElement('text', $elname, $remotemap);
                    if (isset($currentmappings[$remotemap])) {
                        $mform->setDefault($elname, $currentmappings[$remotemap]);
                    }
                    $mform->setType($elname, PARAM_RAW);
                } else {
                    $maparray = $metadata->list_local_to_remote_fields($remotemap, false);
                    if ($maparray) {
                        $maps = array('' => $strunmapped);
                        foreach ($maparray as $i) {
                            $maps[$i] = $i;
                        }
                    } else {
                        $maps = array('' => $strnomappings);
                    }
                    $mform->addElement('select', $elname, $remotemap, $maps);
                    if (isset($currentmappings[$remotemap])) {
                        $mform->setDefault($elname, $currentmappings[$remotemap]);
                    }
                }
            }
            */

            $mform->addElement('html', "<h3>".get_string('externalcourse', 'local_campusconnect')."</h3>");

            $ecssettings = new ecssettings($ecsid);
            $metadata = new metadata($ecssettings, true);
            $remotefields = $metadata->list_remote_fields(true);
            $currentmappings = $metadata->get_export_mappings();

            foreach ($remotefields as $remotemap) {
                $elname = $ecsid.'_'.$remotemap.'_courselink';
                if ($remotemap == 'summary') {
                    $mform->addElement('editor', $elname, $remotemap);
                    $mform->setType($elname, PARAM_RAW);
                    $mform->setDefault($elname, array('text' => $currentmappings[$remotemap], 'format' => FORMAT_HTML));
                } else if ($metadata->is_remote_text_field($remotemap, true)) {
                    $mform->addElement('text', $elname, $remotemap);
                    if (isset($currentmappings[$remotemap])) {
                        $mform->setDefault($elname, $currentmappings[$remotemap]);
                    }
                    $mform->setType($elname, PARAM_RAW);
                } else {
                    $maparray = $metadata->list_local_to_remote_fields($remotemap, true);
                    if ($maparray) {
                        $maps = array('' => $strunmapped);
                        foreach ($maparray as $i) {
                            $maps[$i] = $i;
                        }
                    } else {
                        $maps = array('' => $strnomappings);
                    }
                    $mform->addElement('select', $elname, $remotemap, $maps);
                    if (isset($currentmappings[$remotemap])) {
                        $mform->setDefault($elname, $currentmappings[$remotemap]);
                    }
                }
            }
        }

        $this->add_action_buttons();

    }

    public function set_errors($errors) {
        $form = $this->_form;
        foreach ($errors as $element => $message) {
            $form->setElementError($element, $message);
        }
    }
}