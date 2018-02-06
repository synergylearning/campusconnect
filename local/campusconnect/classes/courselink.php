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
 * Represents a link to an external course
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use html_writer;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot.'/course/lib.php');

/**
 * Holds and updates courselinks created that link fake local courses to real courses on an external server.
 */
class courselink {

    const PERSON_UNIQUECODE = 'ecs_PersonalUniqueCode';
    const PERSON_LOGIN = 'ecs_login';
    const PERSON_UID = 'ecs_uid';
    const PERSON_LOGINUID = 'ecs_loginUID';
    const PERSON_EMAIL = 'ecs_email';
    const PERSON_EPPN = 'ecs_eppn';
    const PERSON_CUSTOM = 'ecs_custom';

    public static $validpersontypes = array(
        self::PERSON_UNIQUECODE, self::PERSON_LOGIN, self::PERSON_UID,
        self::PERSON_LOGINUID, self::PERSON_EMAIL, self::PERSON_EPPN, self::PERSON_CUSTOM
    );

    const PERSON_ID_TYPE = 'ecs_person_id_type'; // Param that stores the type to use.

    const USERFIELD_LEARNINGPROGRESS = 'learningProgress';
    const USERFIELD_GRADE = 'grade';

    public static $validexportmappingfields = array(
        self::PERSON_EPPN, self::PERSON_LOGINUID, self::PERSON_LOGIN, self::PERSON_UID,
        self::PERSON_EMAIL, self::PERSON_UNIQUECODE, self::PERSON_CUSTOM,
        self::USERFIELD_LEARNINGPROGRESS, self::USERFIELD_GRADE
    );
    public static $validimportmappingfields = array(
        self::PERSON_EPPN, self::PERSON_LOGINUID, self::PERSON_LOGIN, self::PERSON_UID,
        self::PERSON_EMAIL, self::PERSON_UNIQUECODE, self::PERSON_CUSTOM
    );

    const INCLUDE_LEGACY_PARAMS = false; // Include the legacy 'ecs_hash' and 'ecs_uid_hash' params in the courselink url.

    protected $recordid;
    protected $courseid;
    protected $url;
    protected $resourceid;
    protected $ecsid;
    protected $mid;
    protected $title;
    protected $participantname;
    protected $summary;
    protected $timemodified;

    public function __construct($data) {
        $this->recordid = $data->id;
        $this->courseid = $data->courseid;
        $this->url = $data->url;
        $this->resourceid = $data->resourceid;
        $this->ecsid = $data->ecsid;
        $this->mid = $data->mid;
        $this->title = $data->title;
        $this->summary = $data->summary;
        $this->participantname = $data->participantname;
        $this->timemodified = $data->timemodified;
    }

    public function get_title() {
        return $this->title;
    }

    public function get_url() {
        return $this->url;
    }

    public function get_link() {
        return html_writer::link($this->url, $this->url);
    }

    public function get_participantname() {
        return $this->participantname." ({$this->ecsid}_{$this->mid})";
    }

    public function get_summary() {
        return $this->summary;
    }

    public function get_timemodified() {
        return $this->timemodified;
    }

    /**
     * Create a new courselink with the details provided.
     * @param int $resourceid the id of this link on the ECS server
     * @param ecssettings $settings the settings for this ECS server
     * @param object $courselink the details of the course from the ECS server
     * @param details $transferdetails the details of where the link came from / went to
     * @return bool false if a problem occurred
     */
    public static function create($resourceid, ecssettings $settings, $courselink, details $transferdetails) {
        global $DB;

        if (is_null($transferdetails)) {
            throw new coding_exception('\local_campusconnect\courselink::create - $transferdetails must not be null. '.
                                       'Did you get here via "refresh_from_ecs"?');
        }

        $mid = $transferdetails->get_sender_mid();
        $ecsid = $settings->get_id();
        $partsettings = new participantsettings($ecsid, $mid);

        if (!$partsettings->is_import_enabled()) {
            return true;
        }

        if (is_array($courselink)) {
            $courselink = reset($courselink);
        }

        if (!self::check_required_fields(false, $courselink, $resourceid)) {
            return true; // Remove from the update list.
        }

        $coursedata = self::map_course_settings($courselink, $settings);

        if ($partsettings->get_import_type() == participantsettings::IMPORT_LINK) {
            if (self::get_by_resourceid($resourceid, $settings->get_id())) {
                mtrace("Cannot create a courselink to resource $resourceid - it already exists.");
                return true; // To remove this update from the list.
            }

            if (!self::check_required_fields(true, $coursedata, $resourceid)) {
                return true;
            }

            $coursedata->category = $settings->get_import_category();

            $baseshortname = $coursedata->shortname;
            $num = 1;
            while ($DB->record_exists('course', array('shortname' => $coursedata->shortname))) {
                $num++;
                $coursedata->shortname = "{$baseshortname}_{$num}";
            }
            $course = create_course($coursedata);

            $ins = new stdClass();
            $ins->courseid = $course->id;
            $ins->url = $courselink->url;
            $ins->resourceid = $resourceid;
            $ins->ecsid = $settings->get_id();
            $ins->mid = $mid;

            $DB->insert_record('local_campusconnect_clink', $ins);

            notification::queue_message($settings->get_id(),
                                        notification::MESSAGE_IMPORT_COURSELINK,
                                        notification::TYPE_CREATE,
                                        $course->id);
        }

        return true;
    }

    /**
     * Update a new courselink with the details provided.
     * @param int $resourceid the id of this link on the ECS server
     * @param ecssettings $settings the settings for this ECS server
     * @param object $courselink the details of the course from the ECS server
     * @param mixed $transferdetails details | null the details of where the link came from / went to
     * @param int $mid set when doing a full update (and $transferdetails = null)
     * @return bool true if successfully updated
     */
    public static function update($resourceid, ecssettings $settings, $courselink, $transferdetails, $mid = null) {
        global $DB;

        if ((is_null($transferdetails) && is_null($mid)) ||
            (!is_null($transferdetails) && !is_null($mid))
        ) {
            throw new coding_exception('\local_campusconnect\courselink::update must set EITHER $transferdetails OR $mid');
        }

        if (is_null($mid)) {
            /** @var $transferdetails details */
            $mid = $transferdetails->get_sender_mid();
            $ecsid = $settings->get_id();
            $partsettings = new participantsettings($ecsid, $mid);

            if (!$partsettings->is_import_enabled()) {
                return true;
            }
        } else {
            $partsettings = null;
        }

        if (is_array($courselink)) {
            $courselink = reset($courselink);
        }

        if (!self::check_required_fields(false, $courselink, $resourceid)) {
            return true; // Remove from the update list.
        }

        $coursedata = self::map_course_settings($courselink, $settings);

        if ($partsettings && $partsettings->get_import_type() == participantsettings::IMPORT_LINK) {
            if (!$currlink = self::get_by_resourceid($resourceid, $settings->get_id())) {
                return self::create($resourceid, $settings, $courselink, $transferdetails);
                //throw new \local_campusconnect\courselink_exception("Cannot update courselink to resource $resourceid - it doesn't exist");
            }

            if ($currlink->mid != $mid) {
                throw new courselink_exception("Participant $mid attempted to update resource created by participant "
                                               ."{$currlink->mid}");
            }

            if (!self::check_required_fields(true, $coursedata, $resourceid)) {
                return true;
            }

            if (!$DB->record_exists('course', array('id' => $currlink->courseid))) {
                // The course has been deleted - recreate it.
                $coursedata->category = $settings->get_import_category();
                $baseshortname = $coursedata->shortname;
                $num = 1;
                while ($DB->record_exists('course', array('shortname' => $coursedata->shortname))) {
                    $num++;
                    $coursedata->shortname = "{$baseshortname}_{$num}";
                }
                $course = create_course($coursedata);

                // Update the courselink record to point at this new course.
                $upd = new stdClass();
                $upd->id = $currlink->id;
                $upd->courseid = $course->id;
                $DB->update_record('local_campusconnect_clink', $upd);

                notification::queue_message($settings->get_id(),
                                            notification::MESSAGE_IMPORT_COURSELINK,
                                            notification::TYPE_CREATE,
                                            $coursedata->id);
            } else {
                // Course still exists - update it.
                $coursedata->id = $currlink->courseid;
                update_course($coursedata);

                notification::queue_message($settings->get_id(),
                                            notification::MESSAGE_IMPORT_COURSELINK,
                                            notification::TYPE_UPDATE,
                                            $coursedata->id);
            }

            if ($currlink->url != $courselink->url) {
                $upd = new stdClass();
                $upd->id = $currlink->id;
                $upd->url = $courselink->url;

                $DB->update_record('local_campusconnect_clink', $upd);
            }
        }

        return true;
    }

    /**
     * Delete the courselink based on the details provided
     * @param int $resourceid the id of this link on the ECS server
     * @param ecssettings $settings the settings for this ECS server
     * @return bool true if successfully deleted
     */
    public static function delete($resourceid, ecssettings $settings) {
        global $DB;

        if ($currlink = self::get_by_resourceid($resourceid, $settings->get_id())) {
            $msg = "{$currlink->courseid} ($resourceid)";
            if ($coursename = $DB->get_field('course', 'fullname', array('id' => $currlink->courseid))) {
                $msg .= ' - '.format_string($coursename);
            }
            notification::queue_message($settings->get_id(),
                                        notification::MESSAGE_IMPORT_COURSELINK,
                                        notification::TYPE_DELETE,
                                        0, $msg);
            delete_course($currlink->courseid, false);
            $DB->delete_records('local_campusconnect_clink', array('id' => $currlink->id));
        }

        return true;
    }

    /**
     * Update all courselinks from the ECS
     * @param ecssettings $ecssettings
     * @param int $singlemid optional - only update courselinks from this participant
     * @return object containing: ->created - array of created resource ids
     *                            ->updated - array of updated resource ids
     *                            ->deleted - array of deleted resource ids
     */
    public static function refresh_from_ecs(ecssettings $ecssettings, $singlemid = null) {
        global $DB;

        $ret = (object)array('created' => array(), 'updated' => array(), 'deleted' => array());

        // Get full list of courselinks from this ECS.
        $courselinks = $DB->get_records('local_campusconnect_clink', array('ecsid' => $ecssettings->get_id()),
                                        '', 'resourceid, ecsid, mid');

        // Get list of participants we are importing from.
        $communities = participantsettings::load_communities($ecssettings);
        $importparticipants = array();
        foreach ($communities as $community) {
            /** @var participantsettings $part */
            foreach ($community->participants as $part) {
                if (!$part->is_import_enabled()) {
                    continue;
                }
                if ($part->get_import_type() != participantsettings::IMPORT_LINK) {
                    continue;
                }
                if (is_null($singlemid) || $part->get_mid() == $singlemid) {
                    $importparticipants[$part->get_mid()] = $part;
                }
            }
        }
        unset($communities);

        // Get full list of courselink resources shared with us.
        $connect = new connect($ecssettings);
        $serverlinks = $connect->get_resource_list(event::RES_COURSELINK);

        // Go through all the links from the server and compare to what we have locally.
        foreach ($serverlinks->get_ids() as $resourceid) {
            // Check if we already have this locally.
            if (isset($courselinks[$resourceid])) {
                $mid = $courselinks[$resourceid]->mid;
                if (!is_null($singlemid) && $mid != $singlemid) {
                    unset($courselinks[$resourceid]);
                    continue; // Skip links that don't match the MID we are interested in.
                }
                if (isset($importparticipants[$mid])) {
                    $details = $connect->get_resource($resourceid, event::RES_COURSELINK,
                                                      connect::CONTENT);
                    self::update($resourceid, $ecssettings, $details, null, $mid);
                    $ret->updated[] = $resourceid;
                    unset($courselinks[$resourceid]); // So we can delete anything left in the list at the end.
                }
            } else {
                // We don't already have this link.
                $details = $connect->get_resource($resourceid, event::RES_COURSELINK,
                                                  connect::CONTENT);
                $transferdetails = $connect->get_resource($resourceid, event::RES_COURSELINK,
                                                          connect::TRANSFERDETAILS);

                if (empty($details)) {
                    continue; // This probably shouldn't occur, but we're just going to ignore it.
                }

                if (!is_null($singlemid) && $transferdetails->get_sender_mid() != $singlemid) {
                    continue; // Skip links that don't match the MID we are interested in.
                }

                self::create($resourceid, $ecssettings, $details, $transferdetails);
                $ret->created[] = $resourceid;
            }
        }

        // Delete any course links still in our local list (they have either been deleted remotely, or they are from
        // participants we no longer import course links from).
        foreach ($courselinks as $courselink) {
            self::delete($courselink->resourceid, $ecssettings);
            $ret->deleted[] = $courselink->resourceid;
        }

        return $ret;
    }

    /**
     * Update all courselinks exported by the given participant
     * @param integer $ecsid the ECS we are connecting to
     * @param integer $mid the MID of the participant we are updating from
     */
    public static function refresh_from_participant($ecsid, $mid) {
        $ecssettings = new ecssettings($ecsid);
        self::refresh_from_ecs($ecssettings, $mid);
    }

    /**
     * Delete all the courselinks to the given participant (used when
     * deleting an ECS or switching off import from a particular participant)
     * @param int $mid the participant ID the course links are associated with
     */
    public static function delete_mid_courselinks($mid) {
        global $DB;

        $courselinks = $DB->get_records('local_campusconnect_clink', array('mid' => $mid));
        foreach ($courselinks as $courselink) {
            delete_course($courselink->courseid);
        }
        $DB->delete_records('local_campusconnect_clink', array('mid' => $mid));
    }

    /**
     * Check if the courseid provided refers to a remote course and return the URL if it does
     * @param int $courseid the ID of the course being viewed
     * @param null $user
     * @return mixed string | false - the URL to redirect to
     */
    public static function check_redirect($courseid, $user = null) {
        global $USER;

        if ($user == null) {
            $user = $USER;
        }

        self::log("\n\n****Checking for external courselink redirect for course {$courseid}****");

        if (!$courselink = self::get_by_courseid($courseid)) {
            self::log("Not an external courselink");
            return false;
        }

        $url = $courselink->url;
        self::log("Link to external url: {$url}");

        $participant = new participantsettings($courselink->ecsid, $courselink->mid);
        if (!isguestuser() && $participant->is_import_token_enabled()) {

            $userdata = $participant->map_export_data($user);
            $userparams = http_build_query($userdata, null, '&');

            // Add the auth token.
            if (strpos($url, '?') !== false) {
                $url .= '&';
            } else {
                $url .= '?';
            }
            $url .= $userparams;

            $hash = self::get_ecs_hash($url, $courselink, $userdata, $participant->is_legacy_export());
            $url .= '&ecs_hash='.$hash;

            self::log("Adding user params: {$userparams}");
            self::log("Adding ecs_hash: {$hash}");
            if ($participant->is_legacy_export() && self::INCLUDE_LEGACY_PARAMS) {
                $hashurl = self::get_encoded_hash_url($courselink, $hash);
                self::log("Adding ecs_hash_url: {$hashurl}");
                $url .= '&ecs_hash_url='.$hashurl;
            }
        }

        self::log("Redirecting to: {$url}");

        return $url;
    }

    /**
     * Check if authentication tokens should be included when visiting the
     * participant that this course link is from.
     *
     * @param $courselink
     * @return bool
     */
    protected static function should_include_token($courselink) {
        $participantsettings = new participantsettings($courselink->ecsid, $courselink->mid);
        return $participantsettings->is_import_token_enabled();
    }

    /**
     * Internal - generate an authentication hash for the given
     * course link
     * @param string $url
     * @param object $courselink
     * @param array $userdata
     * @param bool $uselegacy true to use the legacy method for generating the realm
     * @return string
     */
    protected static function get_ecs_hash($url, $courselink, $userdata, $uselegacy) {
        $ecssettings = new ecssettings($courselink->ecsid);
        $connect = new connect($ecssettings);

        if ($uselegacy) {
            $realm = connect::generate_legacy_realm($courselink->url, $userdata);
        } else {
            $realm = connect::generate_realm($url);
        }
        $post = (object)array('realm' => $realm);
        if (self::INCLUDE_LEGACY_PARAMS) {
            $post->url = $courselink->url;
        }

        return $connect->add_auth($post, $courselink->mid);
    }

    /**
     * Generate the correct encoded URL for the 'ecs_hash_url' param
     * @param courselink $courselink
     * @param string $hash
     * @return string
     */
    protected static function get_encoded_hash_url($courselink, $hash) {
        $ecssettings = new ecssettings($courselink->ecsid);
        $ret = $ecssettings->get_url().'/sys/auths/'.$hash;

        return urlencode($ret);
    }

    /**
     * Get the user object from the personid
     *
     * @param string $personid
     * @param string $personidtype
     * @param participantsettings $participant
     * @return null|object the user object, or null if not found
     */
    public static function get_user_from_personid($personid, $personidtype, $participant) {
        global $CFG, $DB;

        $user = null;

        if ($personidtype == self::PERSON_UID) {
            // Strip off the prefix.
            $siteid = substr(sha1($CFG->wwwroot), 0, 8); // Generate a unique ID from the site URL.
            $personidprefix = 'moodle_'.$siteid.'_usr_';
            if (substr($personid, 0, strlen($personidprefix)) == $personidprefix) {
                $personid = intval(substr($personid, strlen($personidprefix)));
            }
        }

        // Map the field name, then look for a match.
        $map = $participant->get_export_mappings();
        if (!empty($map[$personidtype])) { // This type is mapped onto a Moodle field.
            $moodlefield = $map[$personidtype];
            if (in_array($moodlefield, participantsettings::get_possible_export_fields())) {
                if ($fieldname = $participant->is_custom_field($moodlefield)) {
                    // Look for the personid in the 'user_info_data' table.
                    $sql = 'SELECT u.id, u.username
                              FROM {user} u
                              JOIN {user_info_data} ud ON ud.userid = u.id
                              JOIN {user_info_field} uf ON uf.id = ud.fieldid
                             WHERE uf.shortname = :fieldname AND ud.data = :personid';
                    $users = $DB->get_records_sql($sql, array('fieldname' => $fieldname, 'personid' => $personid));
                } else {
                    // Look for the personid in the 'user' table.
                    $users = $DB->get_records('user', array($moodlefield => $personid), '', 'id, username');
                }
                if (count($users) == 1) {
                    // All OK, we've matched up to an existing user.
                    $user = reset($users);

                } else if (count($users) > 1) {
                    self::log("More than one user found with {$moodlefield} (mapped from {$personidtype}) set to {$personid}");
                }
            }
        }

        return $user;
    }

    /**
     * Get the courselink db record from the courseid
     * @param $courseid
     * @return mixed false | object
     */
    public static function get_by_courseid($courseid) {
        global $DB;
        return $DB->get_record('local_campusconnect_clink', array('courseid' => $courseid));
    }

    /**
     * Get the courselink db record from its resourceid and ecsid
     * @param int $resourceid
     * @param int $ecsid
     * @return mixed false | object
     */
    public static function get_by_resourceid($resourceid, $ecsid) {
        global $DB;
        $params = array('resourceid' => $resourceid, 'ecsid' => $ecsid);
        return $DB->get_record('local_campusconnect_clink', $params);
    }

    /**
     * Check that all required fields are included in this courselink object
     * @param bool $ismapped true if the metadata has already been mapped onto a Moodle course object
     * @param object $courselink
     * @param int $resourceid
     * @return bool true if all required fields are present
     */
    protected static function check_required_fields($ismapped, $courselink, $resourceid) {
        if ($ismapped) {
            $requiredfields = array('shortname', 'fullname');
            $aftermapping = ' (after mapping the metadata)';
        } else {
            $requiredfields = array('id', 'title');
            $aftermapping = '';
        }
        foreach ($requiredfields as $requiredfield) {
            if (!isset($courselink->{$requiredfield}) || !trim($courselink->{$requiredfield})) {
                log::add("Imported courselink from resource {$resourceid} is missing required field".
                         " '{$requiredfield}'{$aftermapping}");
                return false;
            }
        }
        return true;
    }

    /**
     * Generate the Moodle course metadata, based on the metadata details from the ECS server
     * @param object $courselink
     * @param ecssettings $ecssettings
     * @return object
     */
    protected static function map_course_settings($courselink, ecssettings $ecssettings) {

        $metadata = new metadata($ecssettings, true);
        $coursedata = $metadata->map_remote_to_course($courselink);
        $coursedata->summaryformat = FORMAT_HTML;

        return $coursedata;
    }

    /**
     * Retrieve a list of all the imported course links
     * @param integer $ecsid optional - only retrieve links for this ECS
     * @param integer $mid optional - only retrieve links for this MID
     * @return courselink[] the course link details
     */
    public static function list_links($ecsid = null, $mid = null) {
        global $DB;

        if (!is_null($mid) && is_null($ecsid)) {
            throw new coding_exception('\local_campusconnect\courselink::list_links - must specify ecsid if mid is specified');
        }

        $sql = "SELECT cl.*, c.fullname AS title, p.displayname AS participantname, c.summary, c.timemodified
                  FROM {local_campusconnect_clink} cl
                  JOIN {course} c ON cl.courseid = c.id
                  JOIN {local_campusconnect_part} p ON cl.ecsid = p.ecsid AND cl.mid = p.mid";
        $params = array();
        if (!is_null($ecsid)) {
            $params['ecsid'] = $ecsid;
            $sql .= " WHERE cl.ecsid = :ecsid ";
            if (!is_null($mid)) {
                $params['mid'] = $mid;
                $sql .= " AND cl.mid = :mid ";
            }
        }
        $links = $DB->get_records_sql($sql, $params);
        $ret = array();
        foreach ($links as $link) {
            $ret[] = new courselink($link);
        }

        return $ret;
    }

    protected static function log($msg) {
        log::add($msg, true, false, false);
    }
}