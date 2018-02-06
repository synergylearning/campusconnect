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

/*
 * Settings page for campus connect
 *
 * @package    admin_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\connect;
use local_campusconnect\ecssettings;
use local_campusconnect\event;

require_once(dirname(__FILE__).'/../../../config.php');

global $DB, $OUTPUT, $PAGE;

require_once($CFG->libdir.'/adminlib.php');

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/allecs.php'));
$PAGE->set_context(context_system::instance());

if (isset($_GET['fn'])) {
    $function = $_GET['fn'];
}

if (isset($_POST['addnewecs'])) {
    $toadd = array();
    $toadd['name'] = $_POST['name'];
    $toadd['url'] = $_POST['url'];
    $toadd['auth'] = 2;
    $toadd['ecsauth'] = 2;
}

admin_externalpage_setup('allecs');

require_login();
require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

print '<a href="'.new moodle_url('/local/campusconnect/admin/ecs.php').'"><h3>Add New ECS</h3></a><br />';
print '<h4>Available ECS</h4>';
$ecslist = ecssettings::list_ecs(false);
print '<table class="generaltable" width="100%">
        <thead><tr><th class="header c0">Active</th><th class="header c1">Name</th>
        <th class="header c2 lastcol">Actions</th></tr></thead><tbody>';
foreach ($ecslist as $ecsid => $ecs) {
    $ecsdetails = new ecssettings($ecsid);
    $url = $ecsdetails->get_url();
    print '<tr>';
    if ($ecsdetails->is_enabled()) {
        $connection = new connect($ecsdetails);
        $offline = '';
        try {
            $idtest = $connection->get_resource_list(event::RES_COURSELINK);
        } catch (Exception $e) {
            $offline = ' ('.get_string('offline', 'local_campusconnect').')';
        }
        print "<td style='text-align: center'>".get_string('yes')."$offline</td>";
    } else {
        print '<td style="text-align: center">'.get_string('no').'</td>';
    }
    $certexpiry = $ecsdetails->get_certificate_expiry();
    if ($certexpiry) {
        $certexpiry = '<strong>'.get_string('certificateexpiry', 'local_campusconnect').':</strong> '.$certexpiry;
    }
    print "<td><div class='info'>
        <strong><a href='".new moodle_url('/local/campusconnect/admin/ecs.php', array('id' => $ecsid))."'>$ecs</a></strong><br />
        <strong>".get_string('serveraddress', 'local_campusconnect').":</strong> $url $certexpiry
    </div></td>";
    print '<td><a href='.new moodle_url('/local/campusconnect/admin/ecs.php', array('id' => $ecsid)).'>'.
        get_string('edit').'</a> | <a href='.new moodle_url('/local/campusconnect/admin/ecs.php', array(
            'delete' => $ecsid, 'sesskey' => sesskey()
        )).'>'.get_string('delete').'</a></td>';
    print '</tr>';
}
print '</tbody></table>';

echo $OUTPUT->footer();