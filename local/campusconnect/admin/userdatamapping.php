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
 * Mapping user data for course link import/export.
 *
 * @package   local_campusconnect
 * @copyright 2014 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\community;
use local_campusconnect\connect_exception;
use local_campusconnect\ecssettings;
use local_campusconnect\participantsettings;

require_once(dirname(__FILE__).'/../../../config.php');
global $CFG, $OUTPUT, $PAGE;
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/local/campusconnect/admin/userdata_mapping_form.php');

$ecsid = optional_param('ecsid', null, PARAM_INT);
$mid = optional_param('mid', null, PARAM_INT);

$part = null;
$params = array();
if ($ecsid && $mid) {
    $params['ecsid'] = $ecsid;
    $params['mid'] = $mid;
    $part = new participantsettings($ecsid, $mid, null, MUST_EXIST);
    if (!$part->is_export_token_enabled() && !$part->is_import_token_enabled()) {
        $part = null; // Not a participant that has settings to update.
    }
}
admin_externalpage_setup('campusconnectuserdatamapping', '', $params);

if (!$part) {
    $allcommunities = array();
    $ecslist = ecssettings::list_ecs();
    foreach ($ecslist as $ecsid => $ecsname) {
        $settings = new ecssettings($ecsid);
        try {
            $communities = participantsettings::load_communities($settings);
            foreach ($communities as $commid => $community) {
                foreach ($community->participants as $identifier => $participant) {
                    if (!$participant->is_export_token_enabled() && !$participant->is_import_token_enabled()) {
                        $community->remove_participant($participant);
                    }
                }
                if (!$community->has_participants()) {
                    unset($communities[$commid]);
                }
            }
            if ($communities) {
                $allcommunities[$ecsname] = $communities;
            }
        } catch (connect_exception $e) {
            // Ignore any exceptions.
        }
    }

    echo $OUTPUT->header();
    if (!$allcommunities) {
        $url = new moodle_url('/local/campusconnect/admin/participants.php');
        $link = html_writer::link($url, get_string('participants', 'local_campusconnect'));
        echo html_writer::tag('p', get_string('notokenparticipants', 'local_campusconnect', $link));
    } else {
        $table = new html_table();
        $table->head = array(
            get_string('ecs', 'local_campusconnect'),
            get_string('community', 'local_campusconnect'),
            get_string('participant', 'local_campusconnect'),
            ''
        );

        $struserdata = get_string('edituserdatamapping', 'local_campusconnect');
        foreach ($allcommunities as $ecsname => $communities) {
            foreach ($communities as $community) {
                /** @var community $community */
                foreach ($community->participants as $participant) {
                    $url = new moodle_url($PAGE->url, array(
                        'ecsid' => $participant->get_ecs_id(),
                        'mid' => $participant->get_mid()
                    ));
                    $row = array(
                        format_string($ecsname),
                        format_string($community->name),
                        format_string($participant->get_name()),
                        html_writer::link($url, $struserdata),
                    );
                    $table->data[] = $row;
                }
            }
        }
        echo html_writer::table($table);
    }
    echo $OUTPUT->footer();
    die();
}

$PAGE->navbar->add($part->get_displayname());

$custom = array(
    'showexport' => $part->is_import_token_enabled(), // The user data that is 'exported' when a user has imported a course link.
    'showimport' => $part->is_export_token_enabled(), // The user data that is 'imported' when a course link has been followed.
);
$form = new campusconnect_userdata_mapping_form(null, $custom, 'post', '', array('class' => 'userdatamappingform'));

$exportfields = $part->get_export_fields();
$current = array(
    'ecsid' => $part->get_ecs_id(),
    'mid' => $part->get_mid(),
    'exportfields' => array_combine($exportfields, $exportfields),
    'exportfieldmapping' => $part->get_export_mappings(),
    'personuidtype' => $part->get_personuidtype(),
    'importfieldmapping' => $part->get_import_mappings(),
);
$form->set_data($current);

if ($data = $form->get_data()) {
    $data->exportfields = isset($data->exportfields) ? array_keys($data->exportfields) : array();
    $part->save_settings($data);
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('usermappingfor', 'local_campusconnect', $part->get_displayname()));
$form->display();
echo $OUTPUT->footer();