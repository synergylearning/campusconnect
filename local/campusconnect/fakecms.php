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
 * Post messages as if this was a CMS
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\connect;
use local_campusconnect\ecssettings;
use local_campusconnect\event;
use local_campusconnect\participantsettings;

require_once(dirname(__FILE__).'/../../config.php');
global $CFG, $PAGE, $OUTPUT;

$membershipdir = $CFG->dataroot.'/fakecms/';
check_dir_exists($membershipdir, true, true);
if ($filename = optional_param('sendfile', null, PARAM_FILE)) {
    $filepath = $membershipdir.$filename;
    if (file_exists($filepath)) {
        header('Content-type: application/json');
        readfile($filepath);
        die();
    }
}

require_once($CFG->dirroot.'/local/campusconnect/fakecms_form.php');

$url = new moodle_url('/local/campusconnect/fakecms.php');
$PAGE->set_url($url);
$context = context_system::instance();
$PAGE->set_context($context);

require_login();
if (!is_siteadmin()) {
    die("Admin only");
}

$PAGE->set_heading("CMS Emulator");
$PAGE->set_title("CMS Emulator");

// Load the details of all known participants in the configured ECS.
$ecslist = ecssettings::list_ecs();
$participants = array();
$allcommunities = array();
/** @var $cms participantsettings */
$cms = participantsettings::get_cms_participant();
if (!$cms) {
    die("You must configure a CMS import participant, before attempting to use this test code");
}
$cmscid = null;
$cmsmid = $cms->get_mid();
$thismid = null;
foreach ($ecslist as $ecsid => $ecsname) {
    $settings = new ecssettings($ecsid);
    $allcommunities[$ecsid] = participantsettings::load_communities($settings);
    foreach ($allcommunities[$ecsid] as $cid => $community) {
        /** @var $participant participantsettings */
        foreach ($community->participants as $identifier => $participant) {
            $participants[$identifier] = $ecsname.' - '.$participant->get_displayname();
            if ($identifier == $cms->get_identifier()) {
                $cmscid = $cid; // Found the right community, now find out the MID of this participant there.
                /** @var $part2 participantsettings */
                foreach ($community->participants as $id2 => $part2) {
                    if ($part2->is_me()) {
                        $thismid = $part2->get_mid();
                        continue 2;
                    }
                }
                die("Unable to find this VLE in the same community as the CMS - community: {$community->name} ($cid)");
            }
        }
    }
}

if (is_null($cmscid) || is_null($thismid)) {
    die("Not able to find out the community ID or the MID of this VLE in the same community as the CMS");
}

// Find the participants with the same CID and MID as the destination CMS/VLE, but on a different ECS.
$thispart = null;
$cmspart = null;
foreach ($allcommunities as $ecsid => $communities) {
    if ($ecsid == $cms->get_ecs_id()) {
        continue; // Looking for the ECS that the CMS is sending *from* not *to*.
    }
    if (!isset($communities[$cmscid])) {
        continue; // The CMS community is not found on this ECS.
    }
    foreach ($communities[$cmscid]->participants as $identifier => $participant) {
        if ($participant->get_mid() == $cmsmid && $participant->is_me()) {
            // Found a participant in the CMS community, with the CMS MID and which we can send to.
            $cmspart = $participant;
        } else if ($participant->get_mid() == $thismid) {
            // Found a participant in the CMS community, which matches the MID we are receiving on (on the other ECS).
            $thispart = $participant;
        }
    }
}
if (is_null($thispart) || is_null($cmspart)) {
    die("Was not able to identify the CMS participant to send from and the VLE participant to send to");
}

$ecssettings = new ecssettings($cms->get_ecs_id());
$connect = new connect($ecssettings);

// Get list of existing directory trees on ECS.
$dirtrees = $connect->get_resource_list(event::RES_DIRECTORYTREE);
$crses = $connect->get_resource_list(event::RES_COURSE);
$memberships = $connect->get_resource_list(event::RES_COURSE_MEMBERS);

$custom = array(
    'participants' => $participants,
    'cmsparticipant' => $cmspart->get_identifier(),
    'thisparticipant' => $thispart->get_identifier(),
    'dirresources' => $dirtrees->get_ids(),
    'crsresources' => $crses->get_ids(),
    'mbrresources' => $memberships->get_ids(),
);
$frmdata = new stdClass();

// Retrieve existing data and display in the form.
if ($dirid = optional_param('showdir', false, PARAM_INT)) {
    $dirtree = $connect->get_resource($dirid, event::RES_DIRECTORYTREE);
    if (isset($dirtree->nodes)) {
        $node = reset($dirtree->nodes); // Fakecms can only cope with a single node in a data structure.
        $frmdata->dirrootid = $dirtree->rootID;
        $frmdata->dirtreetitle = $dirtree->directoryTreeTitle;
        $frmdata->dirid = $node->id;
        $frmdata->dirtitle = $node->title;
        $frmdata->dirparentid = $node->parent->id;
        $frmdata->dirorder = !empty($node->order) ? $node->order : '';
    } else {
        $frmdata->dirtreetitle = $dirtree->directoryTreeTitle;
        $frmdata->dirid = $dirtree->id;
        $frmdata->dirtitle = $dirtree->title;
        $frmdata->dirparentid = $dirtree->parent->id;
        $frmdata->dirorder = !empty($dirtree->order) ? $dirtree->order : '';
        if (empty($frmdata->dirorder) && !empty($dirtree->parent->order)) {
            $frmdata->dirorder = $dirtree->parent->order; // Fix trees that had the 'order' field in the wrong place.
        }
        $frmdata->dirrootid = $dirtree->rootID;
    }
    $frmdata->diraction = 'update';
    $frmdata->dirresourceid = $dirid;

} else if ($crsid = optional_param('showcrs', false, PARAM_INT)) {
    $crs = $connect->get_resource($crsid, event::RES_COURSE);

    $frmdata->crsorganisation = $crs->organisation;
    $frmdata->crsid = $crs->lectureID;
    $frmdata->crsterm = !empty($crs->term) ? $crs->term : '';
    $frmdata->crstitle = $crs->title;
    $frmdata->crstype = !empty($crs->courseType) ? $crs->courseType : '';
    $frmdata->crsmaxpart = !empty($crs->maxParticipants) ? $crs->maxParticipants : '';
    if (isset($crs->groupScenario)) {
        $frmdata->crsparallel = $crs->groupScenario;
    }

    if (!empty($crs->lecturers)) {
        $i = 1;
        foreach ($crs->lecturers as $lecturer) {
            $frmdata->crslecturerfirst[$i] = $lecturer->firstName;
            $frmdata->crslecturerlast[$i] = $lecturer->lastName;
            $i++;
        }
    }

    if (!empty($crs->allocations)) {
        $i = 1;
        foreach ($crs->allocations as $allocation) {
            $frmdata->crsallparent[$i] = $allocation->parentID;
            $frmdata->crsallorder[$i] = !empty($allocation->order) ? $allocation->order : '';
            $i++;
        }
    }

    if (!empty($crs->groups)) {
        $i = 1;
        foreach ($crs->groups as $pgroup) {
            $frmdata->crsptitle[$i] = $pgroup->title;
            $frmdata->crspid[$i] = $pgroup->id;
            $frmdata->crspcomment[$i] = $pgroup->comment;
            if (!empty($pgroup->lecturers)) {
                $j = 1;
                foreach ($pgroup->lecturers as $lecturer) {
                    $frmdata->crsplecturerfirst[$i][$j] = $lecturer->firstName;
                    $frmdata->crsplecturerlast[$i][$j] = $lecturer->lastName;
                    $j++;
                }
            }
            $i++;
        }
    }

    $frmdata->crsaction = 'update';
    $frmdata->crsresourceid = $crsid;

} else if ($mbrid = optional_param('showmbr', false, PARAM_INT)) {
    $mbr = $connect->get_resource($mbrid, event::RES_COURSE_MEMBERS);

    if (is_array($mbr)) {
        $mbr = reset($mbr);
    }

    $frmdata->mbrcourseid = $mbr->courseID;
    $i = 1;
    foreach ($mbr->members as $member) {
        $frmdata->mbrid[$i] = $member->personID;
        $frmdata->mbrrole[$i] = $member->courseRole;
        if (!empty($member->parallelGroups)) {
            $j = 1;
            foreach ($member->parallelGroups as $pgroup) {
                $frmdata->mbrpgid[$i][$j] = $pgroup->id;
                $frmdata->mbrpgrole[$i][$j] = !empty($pgroup->groupRole) ? $pgroup->groupRole : '';
                $j++;
            }
        }
        $i++;
    }

    $frmdata->mbraction = 'update';
    $frmdata->mbrresourceid = $mbrid;
}

$form = new fakecms_form(null, $custom);
$form->set_data($frmdata);

$msg = null;
if ($data = $form->get_data()) {

    list($srcecs, $srcmid) = explode('_', $data->srcpart);
    list($dstecs, $dstmid) = explode('_', $data->dstpart);
    if ($srcecs != $dstecs) {
        die("Source and destination participants must be on the same ECS server");
    }
    $ecssettings = new ecssettings($srcecs);
    $connect = new connect($ecssettings);

    if (!empty($data->dirsubmit)) {
        if ($data->diraction == 'create' || $data->diraction == 'update') {
            $dirtree = (object)array(
                'rootID' => $data->dirrootid,
                'directoryTreeTitle' => $data->dirtreetitle,
                'nodes' => array(),
            );
            $node = (object)array(
                'id' => $data->dirid,
                'title' => $data->dirtitle,
                'parent' => (object)array(
                    'id' => $data->dirparentid,
                ),
            );
            if (!empty($data->dirorder)) {
                $node->order = $data->dirorder;
            }
            $dirtree->nodes[] = $node;
            if ($data->diraction == 'create') {
                $dirresourceid = $connect->add_resource(event::RES_DIRECTORYTREE, $dirtree, null, $dstmid);
                $msg = 'Created new directory tree with resource id: '.$dirresourceid;
                redirect(new moodle_url($PAGE->url, array('showdir' => $dirresourceid)), $msg, 3);
            } else {
                $connect->update_resource($data->dirresourceid, event::RES_DIRECTORYTREE, $dirtree, null, $dstmid);
                $msg = 'Updated directory tree, resource id: '.$data->dirresourceid;
                redirect(new moodle_url($PAGE->url, array('showdir' => $data->dirresourceid)), $msg, 3);
            }

        } else if ($data->diraction == 'delete') {
            $connect->delete_resource($data->dirresourceid, event::RES_DIRECTORYTREE);
            $msg = 'Deleted directory tree, resource id: '.$data->dirresourceid;
            redirect($PAGE->url, $msg, 3);

        } else if ($data->diraction == 'retrieve') {
            redirect(new moodle_url($PAGE->url, array('showdir' => $data->dirresourceid)));
        }

    } else if (!empty($data->crssubmit)) {
        if ($data->crsaction == 'create' || $data->crsaction == 'update') {
            $crs = (object)array(
                'organisation' => $data->crsorganisation,
                'lectureID' => $data->crsid,
                'term' => $data->crsterm,
                'title' => $data->crstitle,
                'lectureType' => $data->crstype,
                //'maxParticipants' => $data->crsmaxpart,
                //'lecturers' => array(),
                'allocations' => array(),
                'groups' => array(),
            );
            if ($data->crsparallel > 0) {
                $crs->groupScenario = $data->crsparallel;
            }
            for ($i = 1; $i < 4; $i++) {
                if (!empty($data->crslecturerfirst[$i]) && !empty($data->crslecturerlast[$i])) {
                    $crs->lecturers[] = (object)array(
                        'firstName' => $data->crslecturerfirst[$i],
                        'lastName' => $data->crslecturerlast[$i],
                    );
                }
                if (!empty($data->crsallparent[$i])) {
                    $allocation = (object)array(
                        'parentID' => $data->crsallparent[$i],
                    );
                    if (!empty($data->crsallorder[$i])) {
                        $allocation->order = $data->crsallorder[$i];
                    }
                    $crs->allocations[] = $allocation;
                }
                if (!empty($data->crsptitle[$i]) && !empty($data->crspid[$i])) {
                    $pgroup = (object)array(
                        'title' => $data->crsptitle[$i],
                        'id' => $data->crspid[$i],
                        'lecturers' => array(),
                    );
                    if (!empty($data->crspcomment[$i])) {
                        $pgroup->comment = $data->crspcomment[$i];
                    }
                    for ($j = 1; $j <= 4; $j++) {
                        if (!empty($data->crsplecturerfirst[$i][$j]) && !empty($data->crsplecturerlast[$i][$j])) {
                            $pgroup->lecturers[] = (object)array(
                                'firstName' => $data->crsplecturerfirst[$i][$j],
                                'lastName' => $data->crsplecturerlast[$i][$j],
                            );
                        }
                    }
                    $crs->parallelGroups[] = $pgroup;
                }
            }

            if ($data->crsaction == 'create') {
                $crsresourceid = $connect->add_resource(event::RES_COURSE, $crs, null, $dstmid);
                $msg = 'Created new course with resource id: '.$crsresourceid;
                redirect(new moodle_url($PAGE->url, array('showcrs' => $crsresourceid)), $msg, 3);
            } else {
                $connect->update_resource($data->crsresourceid, event::RES_COURSE, $crs, null, $dstmid);
                $msg = 'Updated course, resource id: '.$data->crsresourceid;
                redirect(new moodle_url($PAGE->url, array('showcrs' => $data->crsresourceid)), $msg, 3);
            }

        } else if ($data->crsaction == 'delete') {
            $connect->delete_resource($data->crsresourceid, event::RES_COURSE);
            $msg = 'Deleted course, resource id: '.$data->crsresourceid;
            redirect($PAGE->url, $msg, 3);

        } else if ($data->crsaction == 'retrieve') {
            redirect(new moodle_url($PAGE->url, array('showcrs' => $data->crsresourceid)));
        }

    } else if (!empty($data->mbrsubmit)) {
        if ($data->mbraction == 'create' || $data->mbraction == 'update') {
            $mbr = (object)array(
                'courseID' => $data->mbrcourseid,
                'members' => array(),
            );
            for ($i = 1; $i <= 5; $i++) {
                if (!empty($data->mbrid[$i])) {
                    $mbrmbr = new stdClass();
                    $mbrmbr->personID = $data->mbrid[$i];
                    if (!empty($data->mbrrole[$i])) {
                        $mbrmbr->courseRole = $data->mbrrole[$i];
                    }
                    for ($j = 1; $j <= 5; $j++) {
                        if (!empty($data->mbrpgid[$i][$j])) {
                            $pgroup = new stdClass();
                            $pgroup->id = $data->mbrpgid[$i][$j];
                            if (!empty($data->mbrpgrole[$i][$j])) {
                                $pgroup->groupRole = $data->mbrpgrole[$i][$j];
                            }
                            if (!isset($mbrmbr->parallelGroups)) {
                                $mbrmbr->parallelGroups = array();
                            }
                            $mbrmbr->parallelGroups[] = $pgroup;
                        }
                    }
                    $mbr->members[] = $mbrmbr;
                }
            }
            if ($data->mbraction == 'create') {
                $data->mbrresourceid = $connect->add_resource(event::RES_COURSE_MEMBERS, array(), null, $dstmid);
                $msg = 'Created new membership list with resource id: '.$data->mbrresourceid;
            } else {
                $msg = 'Updated membership list, resource id: '.$data->mbrresourceid;
            }

            // Write the contents to a file, so it can be served back to the VLE later - put the access URL in the resource.
            file_put_contents($membershipdir.$data->mbrresourceid, json_encode($mbr));
            $url = new moodle_url('/local/campusconnect/fakecms.php', array('sendfile' => $data->mbrresourceid));
            $urls = $url->out();
            $connect->update_resource($data->mbrresourceid, event::RES_COURSE_MEMBERS, $urls, null, $dstmid);
            redirect(new moodle_url($PAGE->url, array('showmbr' => $data->mbrresourceid)), $msg, 3);

        } else if ($data->mbraction == 'delete') {
            $connect->delete_resource($data->mbrresourceid, event::RES_COURSE_MEMBERS);
            $msg = 'Deleted membership list, resource id: '.$data->mbrresourceid;
            redirect($PAGE->url, $msg, 3);

        } else if ($data->mbraction == 'retrieve') {
            redirect(new moodle_url($PAGE->url, array('showmbr' => $data->mbrresourceid)));
        }
    }
}

echo $OUTPUT->header();
echo html_writer::tag('p', 'This form allows you to send data from one participant to another, as if it was from a Campus Management System. It is only meant for testing purposes and you will need to configure two connections to the ECS for it to work (with one connection acting as the CMS, the other acting as the destination VLE).');
if (!empty($msg)) {
    echo $OUTPUT->box($msg);
}
$form->display();
echo $OUTPUT->footer();
