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

use local_campusconnect\connect_exception;
use local_campusconnect\ecssettings;
use local_campusconnect\participantsettings;

require_once(dirname(__FILE__).'/../../../config.php');

global $CFG, $PAGE, $OUTPUT;

require_once($CFG->libdir.'/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/campusconnect/admin/participants.php'));
$PAGE->set_context(context_system::instance());

admin_externalpage_setup('campusconnectparticipants');

$refreshdone = optional_param('refreshdone', null, PARAM_INT);
$refreshmsg = null;

$error = array();
$ecslist = ecssettings::list_ecs();
$allcommunities = array();
foreach ($ecslist as $ecsid => $ecsname) {
    $settings = new ecssettings($ecsid);
    try {
        $allcommunities[$ecsname] = participantsettings::load_communities($settings);
    } catch (connect_exception $e) {
        $error[$ecsname] = $e->getMessage();
    }

    if ($refreshdone == $ecsid) {
        $refreshmsg = get_string('refreshdone', 'local_campusconnect', $ecsname);
        $refreshmsg = $OUTPUT->notification($refreshmsg, 'campusconnect_refreshdone');
    }
}

$settingerrors = array();
$confirmmsgs = array();
$confirmparams = array();
if (optional_param('saveparticipants', false, PARAM_TEXT)) {
    require_sesskey();

    // Array of participant identifiers that were included in this update.
    $updateparticipants = required_param_array('updateparticipants', PARAM_ALPHANUMEXT);
    // Array of participant identifiers to export to.
    $export = optional_param_array('export', array(), PARAM_ALPHANUMEXT);
    $exportenrolment = optional_param_array('exportenrolment', array(), PARAM_ALPHANUMEXT);
    $exporttoken = optional_param_array('exporttoken', array(), PARAM_ALPHANUMEXT);
    $uselegacy = optional_param_array('uselegacy', array(), PARAM_ALPHANUMEXT);
    // Array of participant identifiers to import from.
    $import = optional_param_array('import', array(), PARAM_ALPHANUMEXT);
    $importenrolment = optional_param_array('importenrolment', array(), PARAM_ALPHANUMEXT);
    $importtoken = optional_param_array('importtoken', array(), PARAM_ALPHANUMEXT);
    // Array of import types (indexed by participant identifiers).
    $importtypes = required_param_array('importtype', PARAM_INT);

    // User has confirmed the change (only needed if the change would remove data).
    $confirm = optional_param('confirm', false, PARAM_BOOL);

    foreach ($allcommunities as $communities) {
        foreach ($communities as $community) {
            foreach ($community->participants as $identifier => $participant) {
                /** @var $participant participantsettings */
                if (!in_array($identifier, $updateparticipants)) {
                    continue; // This participant was not in the list being updated.
                }

                $tosave = new stdClass;
                $tosave->import = in_array($identifier, $import);
                $tosave->importenrolment = in_array($identifier, $importenrolment);
                $tosave->importtoken = in_array($identifier, $importtoken);
                $tosave->export = in_array($identifier, $export);
                $tosave->exportenrolment = in_array($identifier, $exportenrolment);
                $tosave->exporttoken = in_array($identifier, $exporttoken);
                $tosave->uselegacy = in_array($identifier, $uselegacy);
                $tosave->importtype = $importtypes[$identifier];

                if ($err = $participant->check_settings($tosave)) {
                    $settingerrors[] = $err;
                    continue;
                }

                if (!$confirm) {
                    if ($confirmmsg = $participant->get_confirm_message($tosave)) {
                        // Not confirmed and needs confirming, gather the output message and the parameters required.
                        $confirmmsgs[] = $confirmmsg;
                        $confirmparams["updateparticipants[$identifier]"] = $identifier;
                        if ($tosave->import) {
                            $confirmparams["import[$identifier]"] = $identifier;
                        }
                        if ($tosave->export) {
                            $confirmparams["export[$identifier]"] = $identifier;
                        }
                        $confirmparams["importtype[$identifier]"] = $tosave->importtype;
                        continue;
                    }
                }

                $participant->save_settings($tosave);
            }
        }
    }
}

$PAGE->requires->string_for_js('changesmadereallygoaway', 'moodle');
$PAGE->requires->yui_module('moodle-local_campusconnect-participantsettings', 'M.local_campusconnect.participantsettings.init');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_campusconnect'));

if (!empty($confirmmsgs)) {
    $confirmparams['saveparticipants'] = 1;
    $confirmparams['confirm'] = 1;
    $confirmparams['sesskey'] = sesskey();
    $confirmurl = new moodle_url($PAGE->url, $confirmparams);
    echo $OUTPUT->confirm(implode('<br/>', $confirmmsgs), $confirmurl, $PAGE->url);
    echo $OUTPUT->footer();
    die();
}

if ($ecsid = optional_param('refresh', null, PARAM_INT)) {
    require_sesskey();

    require_once($CFG->dirroot.'/local/campusconnect/lib.php');
    $ecssettings = new ecssettings($ecsid);

    echo $OUTPUT->heading(get_string('refreshing', 'local_campusconnect', $ecssettings->get_name()), 3);

    echo $OUTPUT->box_start();
    $ret = local_campusconnect_refresh_ecs($ecssettings, true);

    $table = new html_table();
    $table->head = array(
        '',
        get_string('created', 'local_campusconnect'),
        get_string('updated', 'local_campusconnect'),
        get_string('deleted', 'local_campusconnect')
    );
    $table->data = array();

    $errors = array();
    foreach ($ret as $item => $data) {
        $row = array(
            $item,
            count($data->created),
            count($data->updated),
            count($data->deleted)
        );
        $table->data[] = $row;
        if (!empty($data->errors)) {
            $errors = array_merge($errors, $data->errors);
        }
    }
    echo html_writer::table($table);

    if (!empty($errors)) {
        $err = '';
        foreach ($errors as $error) {
            $err .= html_writer::tag('li', $error);
        }
        echo html_writer::tag('ul', $err, array('class' => 'error'));
    }

    $redir = new moodle_url($PAGE->url, array('refreshdone' => $ecsid));
    echo $OUTPUT->continue_button($redir);

    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
    die();
}

echo $refreshmsg;

$importopts = array(
    participantsettings::IMPORT_LINK => get_string('ecscourselink', 'local_campusconnect'),
    participantsettings::IMPORT_COURSE => get_string('course', 'local_campusconnect'),
    participantsettings::IMPORT_CMS => get_string('campusmanagement', 'local_campusconnect')
);

$strrefresh = get_string('refreshecs', 'local_campusconnect');
$strparticipants = get_string('participants', 'local_campusconnect');
$strfurtherinformation = get_string('furtherinformation', 'local_campusconnect');
$strexport = get_string('export', 'local_campusconnect');
$strimport = get_string('import', 'local_campusconnect');
$strimporttype = get_string('importtype', 'local_campusconnect');

$strthisvle = get_string('thisvle', 'local_campusconnect');
$strprovider = get_string('provider', 'local_campusconnect');
$strdomain = get_string('domainname', 'local_campusconnect');
$stremail = get_string('email', 'local_campusconnect');
$strabbr = get_string('abbr', 'local_campusconnect');
$strpartid = get_string('partid', 'local_campusconnect');

$strsavechanges = get_string('savechanges');
$strcancel = get_string('cancel');

if ($error) {
    foreach ($error as $ecsname => $errormessage) {
        echo $OUTPUT->notification($ecsname.': '.get_string('errorparticipants', 'local_campusconnect', $errormessage));
    }
}
if ($settingerrors) {
    foreach ($settingerrors as $msg) {
        echo $OUTPUT->notification($msg);
    }
}

foreach ($allcommunities as $ecsname => $communities) {
    $firstcommunity = reset($communities);
    if ($firstcommunity) {
        $url = new moodle_url('/local/campusconnect/admin/participants.php', array(
            'refresh' => $firstcommunity->ecsid,
            'sesskey' => sesskey()
        ));
        $refreshlink = $OUTPUT->single_button($url, $strrefresh, 'POST');
        $refreshlink = html_writer::tag('span', $refreshlink, array('class' => 'campusconnect_refresh'));
    } else {
        $refreshlink = '';
    }
    echo "<h3>{$ecsname}{$refreshlink}</h3>";
    echo '<hr>';
    echo '<form action="" method="POST">';
    foreach ($communities as $community) {
        echo "<h4>{$community->name}</h4>";
        echo '<table class="generaltable participantsettings" width="100%">
        <thead>
            <tr>
                <th class="header c0">'.$strparticipants.'</th>
                <th class="header c1">'.$strfurtherinformation.'</th>
                <th class="header c2">'.$strexport.'</th>
                <th class="header c3">'.$strimport.'</th>
                <th class="header c4 lastcol">'.$strimporttype.'</th>
            </tr>
        </thead>
        <tbody>';
        if (empty($community->participants)) {
            echo '<tr><td colspan="5">';
            echo get_string('noparticipants', 'local_campusconnect');
            echo '</tr>';
        } else {
            foreach ($community->participants as $participant) {
                $partid = $participant->get_identifier();
                $name = s($participant->get_name());
                $userdataurl = new moodle_url('/local/campusconnect/admin/userdatamapping.php',
                                              array('ecsid' => $participant->get_ecs_id(), 'mid' => $participant->get_mid()));
                $userdatalink = '<br/>'.html_writer::link($userdataurl,
                                                          get_string('edituserdatamapping', 'local_campusconnect'));
                echo '<tr><td><h4';
                if ($participant->is_me()) {
                    echo ' class="itsme"';
                    $name .= " ({$strthisvle})";
                }
                echo '>';
                echo $name;
                echo '</h4></td><td>';
                echo "<strong>{$strprovider}:</strong> ".$participant->get_organisation()."<br />";
                echo "<strong>{$strdomain}:</strong> ".$participant->get_domain()."<br />";
                echo "<strong>{$stremail}:</strong> ".$participant->get_email()."<br />";
                echo "<strong>{$strabbr}:</strong> ".$participant->get_organisation_abbr()."<br />";
                echo "<strong>{$strpartid}:</strong> ".$partid;
                echo '</td>';
                echo "<td>";
                echo html_writer::checkbox('export[]', $partid,
                                           $participant->is_export_enabled(),
                                           get_string('externalcourse', 'local_campusconnect'),
                                           array('id' => 'export_'.$partid));
                echo '<br/>';
                echo html_writer::checkbox('exportenrolment[]', $partid,
                                           $participant->is_export_enrolment_enabled(),
                                           get_string('enrolmentstatus', 'local_campusconnect'),
                                           array('id' => 'exportenrolment_'.$partid));
                echo '<br/>';
                echo html_writer::checkbox('exporttoken[]', $partid,
                                           $participant->is_export_token_enabled(),
                                           get_string('authenticationtoken', 'local_campusconnect'),
                                           array('id' => 'exporttoken_'.$partid));
                if ($participant->is_export_token_enabled()) {
                    echo $userdatalink;
                }
                echo '</td>';
                echo "<td>";
                echo html_writer::checkbox('import[]', $partid,
                                           $participant->is_import_enabled(),
                                           get_string('enabled', 'local_campusconnect'),
                                           array('id' => 'import_'.$partid));
                echo '<br/>';
                echo html_writer::checkbox('importenrolment[]', $partid,
                                           $participant->is_import_enrolment_enabled(),
                                           get_string('enrolmentstatus', 'local_campusconnect'),
                                           array('id' => 'importenrolment_'.$partid));
                echo '<br/>';
                echo html_writer::checkbox('importtoken[]', $partid,
                                           $participant->is_import_token_enabled(),
                                           get_string('authenticationtoken', 'local_campusconnect'),
                                           array('id' => 'importtoken_'.$partid));
                echo '<br/>';
                echo html_writer::checkbox('uselegacy[]', $partid,
                                           $participant->is_legacy_export(),
                                           get_string('uselegacytoken', 'local_campusconnect'),
                                           array('id' => 'uselegacy_'.$partid));
                if ($participant->is_import_token_enabled()) {
                    echo $userdatalink;
                }
                echo '<br/>';
                echo '</td>';
                echo "<td style='text-align: center'>";
                echo html_writer::select($importopts, 'importtype['.$partid.']',
                                         $participant->get_import_type(), '');

                echo html_writer::empty_tag('input', array(
                    'type' => 'hidden',
                    'name' => 'updateparticipants[]',
                    'value' => $partid,
                    'class' => 'participantidentifier'
                ));
                echo html_writer::empty_tag('input', array(
                    'type' => 'hidden',
                    'name' => 'sesskey',
                    'value' => sesskey()
                ));
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }
    echo '<div style="float: right;">
        <input type="submit" name="saveparticipants" value="'.$strsavechanges.'" />
        <input onclick="M.local_campusconnect.participantsettings.hasChanges=false;
                        window.location.reload( true );" type="button" value="'.$strcancel.'" />
    </div>';
    echo '</form>';
    echo '<br style="clear:both;" /><br />';
}

echo $OUTPUT->footer();