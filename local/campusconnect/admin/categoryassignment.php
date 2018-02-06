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

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');
require_once("$CFG->libdir/formslib.php");

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/categoryassignment.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectcategoryassignment');

require_login();
require_capability('moodle/site:config', context_system::instance());

class campusconnect_category_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'categoryassignment', get_string('categoryassignment', 'local_campusconnect'));

        $cats = array();
        $categories = coursecat::get(0)->get_children();
        foreach ($categories as $category) {
            $cats[$category->id] = $category->name;
        }
        $mform->addElement('select', 'importcat', get_string('importcat', 'local_campusconnect'), $cats);

        $mform->addElement('select', 'attributename', get_string('attributename', 'local_campusconnect'), array(
            '1' => 'Community', '2' => 'Participant ID'
        ));

        $radioarray = array();
        $radioarray[] = $mform->createElement('radio', 'cc_mapping', '', get_string('fixedvalue', 'local_campusconnect'), 'mapping_fixed', 'onclick=cc_switch_mapping_fixed()');
        $radioarray[] = $mform->createElement('radio', 'cc_mapping', '', get_string('daterange', 'local_campusconnect'), 'mapping_date', 'onclick=cc_switch_mapping_date()');
        $mform->addGroup($radioarray, 'radioar', get_string('mappingtype', 'local_campusconnect'), array(' '), false);
        $mform->setDefault('cc_mapping', 'mapping_fixed');

        $mform->addElement('text', 'attribute', get_string('attribute', 'local_campusconnect'));

        $mform->addElement('date_selector', 'daterangefrom', get_string('from'));
        $mform->addElement('date_selector', 'daterangeto', get_string('to'));

        $mform->disabledIf('attribute', 'cc_mapping', 'eq', 'mapping_date');
        $mform->disabledIf('daterangefrom', 'cc_mapping', 'eq', 'mapping_fixed');;
        $mform->disabledIf('daterangeto', 'cc_mapping', 'eq', 'mapping_fixed');

        $this->add_action_buttons();
    }
}

$mform = new campusconnect_category_form();

if ($mform->is_cancelled()) {

    redirect("{$CFG->wwwroot}/admin/campusconnect/categoryassignment.php", '', 0);

} else if ($post = $mform->get_data()) {

    print 'TODO';

} else {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

    print get_string('currentassignments', 'local_campusconnect');

    print '<table class="generaltable"><thead><tr><th class="header">Current Assignments</th></tr></thead></table>';

    print '<h2>'.get_string('newassignment', 'local_campusconnect').'</h2>';

    $mform->display();

    ?>
    <script type="text/javascript">

        cc_switch_mapping_fixed();

        function cc_switch_mapping_fixed() {
            document.getElementById('fitem_id_attribute').style.display = 'block';
            document.getElementById('fitem_id_daterangefrom').style.display = 'none';
            document.getElementById('fitem_id_daterangeto').style.display = 'none';
        }

        function cc_switch_mapping_date() {
            document.getElementById('fitem_id_attribute').style.display = 'none';
            document.getElementById('fitem_id_daterangefrom').style.display = 'block';
            document.getElementById('fitem_id_daterangeto').style.display = 'block';
        }
    </script>
    <?php

    echo $OUTPUT->footer();

}