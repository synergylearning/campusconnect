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
 * Represents a participant (VLE/CMS) in an ECS community.
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use html_writer;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class participantsettings {

    const IMPORT_LINK = 1;
    const IMPORT_COURSE = 2;
    const IMPORT_CMS = 3;

    const CUSTOM_FIELD_PREFIX = 'custom_';

    // Settings saved locally in the database.
    protected $recordid = null;
    protected $ecsid = null;
    /** @var int $mid */
    protected $mid = null;
    protected $pid = null;
    protected $export = false;
    protected $exportenrolment = true;
    protected $exporttoken = true; // Use the token when a user from a remote site follows an exported course link.
    protected $import = false;
    protected $importenrolment = true;
    protected $importtoken = true; // Use the token when the user follows an imported course link.
    protected $importtype = self::IMPORT_LINK;
    protected $uselegacy = false;
    protected $personuidtype = courselink::PERSON_UID;
    protected $exportfields = null;
    protected $exportfieldmapping = null;
    protected $importfieldmapping = null;
    protected $orgabbr = null;

    protected $displayname = null; // Constructed from the community name + part name.
    protected $active = 1;

    // Settings loaded from the ECS server.
    protected $name = null;
    protected $communityname = null;
    protected $description = null;
    protected $dns = null;
    protected $email = null;
    protected $org = null;
    protected $itsyou = null;

    // Flagged as being exported in the current course.
    protected $exported = null;

    protected static $validsettings = array(
        'export', 'exportenrolment', 'exporttoken',
        'import', 'importenrolment', 'importtoken', 'importtype',
        'uselegacy', 'personuidtype', 'exportfields', 'exportfieldmapping',
        'importfieldmapping'
    );
    protected static $ecssettings = array('name', 'description', 'dns', 'email', 'org', 'orgabbr', 'communityname', 'itsyou');

    protected static $pidtomid = null;

    protected static $defaultexportfields = array(
        courselink::PERSON_UID,
        courselink::PERSON_LOGIN,
        courselink::PERSON_EMAIL,
    );
    protected static $defaultexportmapping = array(
        courselink::PERSON_EPPN => null,
        courselink::PERSON_LOGINUID => null,
        courselink::PERSON_LOGIN => 'username',
        courselink::PERSON_UID => 'id',
        courselink::PERSON_EMAIL => 'email',
        courselink::PERSON_UNIQUECODE => null,
        courselink::PERSON_CUSTOM => null,
        courselink::USERFIELD_LEARNINGPROGRESS => null,
        courselink::USERFIELD_GRADE => null,
    );
    protected static $defaultimportmapping = array(
        courselink::PERSON_EPPN => null,
        courselink::PERSON_LOGINUID => null,
        courselink::PERSON_LOGIN => null,
        courselink::PERSON_UID => null,
        courselink::PERSON_EMAIL => 'email',
        courselink::PERSON_UNIQUECODE => null,
        courselink::PERSON_CUSTOM => null,
        courselink::USERFIELD_LEARNINGPROGRESS => null,
        courselink::USERFIELD_GRADE => null,
    );

    protected static $possibleexportfields = array(
        'id', 'username', 'idnumber', 'firstname', 'lastname', 'email', 'icq', 'skype', 'yahoo', 'aim', 'msn', 'phone1', 'phone2',
        'institution', 'department', 'address', 'city', 'country'
    );

    // Not 'id', 'username', 'firstname', 'lastname', as these mappings are hard-coded.
    protected static $possibleimportfields = array(
        'idnumber', 'email', 'icq', 'skype', 'yahoo', 'aim', 'msn', 'phone1', 'phone2',
        'institution', 'department', 'address', 'city', 'country'
    );

    /**
     * @param mixed $ecsidordata either the ID of the ECS or an object containing
     *                           the settings record loaded from the database
     * @param int $mid optional the participant ID (required if the ECS ID is provided)
     * @param object $extradetails details about the participant loaded from the ECS
     * @param int $strictness throw an exception if the participant is not found
     * @throws coding_exception
     */
    public function __construct($ecsidordata, $mid = null, $extradetails = null, $strictness = IGNORE_MISSING) {
        global $DB;

        if (is_object($ecsidordata)) {
            // Data already loaded from database - store it.
            $this->set_settings($ecsidordata);
        } else {
            if (is_null($mid)) {
                throw new coding_exception("Must set the participant id (mid) if not passing in the database record");
            }
            $this->load_settings($ecsidordata, $mid, $strictness);
        }

        if (isset($extradetails)) {
            $orgabbr = null;
            foreach ($extradetails as $name => $value) {
                if ($name == 'org') {
                    $this->org = $value->name;
                    $orgabbr = $value->abbr;
                    continue;
                }
                if (in_array($name, self::$ecssettings)) {
                    $this->$name = $value;
                }
            }
            // Save the PID, if not already recorded.
            if (!empty($extradetails->pid) && $extradetails->pid != $this->pid) {
                $this->pid = $extradetails->pid;
                $DB->set_field('local_campusconnect_part', 'pid', $this->pid, array('id' => $this->recordid));
            }
            // Save the orgabbr, if not already recorded.
            if ($orgabbr && $orgabbr != $this->orgabbr) {
                $this->orgabbr = $orgabbr;
                if ($this->orgabbr != 'n/a') {
                    $DB->set_field('local_campusconnect_part', 'orgabbr', $this->orgabbr, ['id' => $this->recordid]);
                }
            }

            $this->set_display_name();
        }
    }

    public function get_ecs_id() {
        return $this->ecsid;
    }

    public function get_mid() {
        return $this->mid;
    }

    public function get_pid() {
        return $this->pid;
    }

    public function get_identifier() {
        return "{$this->ecsid}_{$this->mid}";
    }

    public function is_export_enabled() {
        return (bool)$this->export;
    }

    public function is_export_token_enabled() {
        return ($this->export && $this->exporttoken);
    }

    public function is_export_enrolment_enabled() {
        return ($this->export && $this->exportenrolment);
    }

    public function is_legacy_export() {
        return (bool)$this->uselegacy;
    }

    public function is_import_enabled() {
        return (bool)$this->import;
    }

    public function is_import_token_enabled() {
        return ($this->import && $this->importtoken);
    }

    public function is_import_enrolment_enabled() {
        return ($this->import && $this->importenrolment);
    }

    public function get_import_type() {
        return $this->importtype;
    }

    public function get_displayname() {
        return $this->displayname;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_description() {
        return $this->description;
    }

    public function get_domain() {
        return $this->dns;
    }

    public function get_email() {
        return $this->email;
    }

    public function get_organisation() {
        return $this->org;
    }

    public function get_organisation_abbr() {
        return $this->orgabbr;
    }

    public function get_export_fields() {
        if ($this->exportfields !== null) {
            return $this->exportfields;
        }
        return self::$defaultexportfields;
    }

    public function get_export_mappings() {
        if ($this->exportfieldmapping !== null) {
            return $this->exportfieldmapping;
        }
        return self::$defaultexportmapping;
    }

    public function get_personuidtype() {
        return $this->personuidtype;
    }

    public function get_import_mappings() {
        if ($this->importfieldmapping !== null) {
            return $this->importfieldmapping;
        }
        return self::$defaultimportmapping;
    }

    /**
     * Used in unit tests to reset the known list of custom fields.
     */
    public static function reset_custom_fields() {
        self::get_custom_fields(true);
    }

    protected static function get_custom_fields($reset = false) {
        global $DB;
        static $customfields = null;
        if ($reset || $customfields === null) {
            // Don't include 'textarea' customfields, as the data for them is not loaded into the user object.
            $customfields = $DB->get_fieldset_select('user_info_field', 'shortname', "datatype <> 'textarea'");
            foreach ($customfields as &$customfield) {
                $customfield = self::CUSTOM_FIELD_PREFIX.$customfield;
            }
        }
        return $customfields;
    }

    public static function get_possible_export_fields() {
        return array_merge(self::$possibleexportfields, self::get_custom_fields());
    }

    public static function get_possible_import_fields() {
        return array_merge(self::$possibleimportfields, self::get_custom_fields());
    }

    public function is_me() {
        return ($this->itsyou == true);
    }

    /**
     * Check if the participant is currently exported.
     * @return bool
     */
    public function is_exported() {
        if (is_null($this->exported)) {
            throw new coding_exception('is_exported can only be called after set_exported has been called '.
                                       '(usually via campusconnect_export)');
        }
        return $this->exported;
    }

    public function show_exported($exported) {
        $this->exported = $exported;
    }

    protected function load_settings($ecsid, $mid, $strictness = IGNORE_MISSING) {
        global $DB;

        $settings = $DB->get_record('local_campusconnect_part', array(
            'mid' => $mid,
            'ecsid' => $ecsid
        ), '*', $strictness);
        if ($settings) {
            $this->set_settings($settings);
        } else {
            $ins = new stdClass();
            $this->mid = $ins->mid = $mid;
            $this->ecsid = $ins->ecsid = $ecsid;
            foreach (self::$validsettings as $setting) {
                $ins->$setting = $this->$setting; // Set all the defaults from this class.
            }
            $this->active = $ins->active = 1;
            $this->recordid = $this->save_to_database($ins);
        }
    }

    /**
     * Set the display name for this participant (and save it in
     * the database, so it can be shown later, without needing to
     * connect to the ECS)
     */
    protected function set_display_name() {
        if (empty($this->name)) {
            return;
        }
        $displayname = $this->name;
        if (!empty($this->communityname)) {
            $displayname = $this->communityname.': '.$displayname;
        }

        if ($displayname != $this->displayname) {
            $this->displayname = $displayname;
            $upd = new stdClass();
            $upd->id = $this->recordid;
            $upd->displayname = $displayname;
            $this->save_to_database($upd);
        }
    }

    public function save_settings($settings) {
        global $CFG;

        $settings = (array)$settings; // Avoid updating passed-in objects.
        $settings = (object)$settings;

        // Check to see if anything has changed and all settings are valid.
        $checksettings = array('export', 'exportenrolment', 'exporttoken', 'import', 'importenrolment', 'importtoken', 'uselegacy');
        foreach ($checksettings as $setting) {
            if ($this->$setting) {
                if (isset($settings->$setting) && !$settings->$setting) {
                    $settings->$setting = false;
                } else {
                    unset($settings->$setting); // Unchanged.
                }
            } else {
                if (!empty($settings->$setting)) {
                    $settings->$setting = true;
                } else {
                    unset($settings->$setting); // Unchanged.
                }
            }
        }

        if (isset($settings->importtype)) {
            $validimporttypes = array(self::IMPORT_LINK, self::IMPORT_COURSE, self::IMPORT_CMS);
            if (!in_array($settings->importtype, $validimporttypes)) {
                throw new coding_exception("Invalid importtype: $settings->importtype");
            }
            if ($settings->importtype == $this->importtype) {
                unset($settings->importtype);
            }
        }

        if (isset($settings->personuidtype)) {
            if (!in_array($settings->personuidtype, courselink::$validpersontypes)) {
                unset($settings->personuidtype);
            } else if ($settings->personuidtype == $this->personuidtype) {
                unset($settings->personuidtype); // Unchanged.
            }
        }

        if (isset($settings->exportfields)) {
            $settings->exportfields = array_intersect($settings->exportfields, courselink::$validexportmappingfields);
            sort($settings->exportfields);
            if ($this->exportfields) {
                sort($this->exportfields);
                if ($settings->exportfields == $this->exportfields) {
                    unset($settings->exportfields); // Unchanged.
                }
            }
        }
        if (isset($settings->exportfieldmapping)) {
            $possible = self::get_possible_export_fields();
            foreach ($settings->exportfieldmapping as $ecsfield => $moodlefield) {
                if (!in_array($ecsfield, courselink::$validexportmappingfields)) {
                    unset($settings->exportfieldmapping[$ecsfield]);
                }
                if (!$moodlefield) {
                    $moodlefield = null;
                } else if (!in_array($moodlefield, $possible)) {
                    $current = $this->get_export_mappings(); // Retrieve the current, or the default.
                    $settings->exportfieldmapping[$ecsfield] = $current[$ecsfield];
                }
            }
            ksort($settings->exportfieldmapping);
            if ($this->exportfieldmapping) {
                ksort($this->exportfieldmapping);
                if ($settings->exportfieldmapping == $this->exportfieldmapping) {
                    unset($settings->exportfieldmapping); // Unchanged.
                }
            }
        }
        if (isset($settings->importfieldmapping)) {
            $possible = self::get_possible_import_fields();
            foreach ($settings->importfieldmapping as $ecsfield => $moodlefield) {
                if (!in_array($ecsfield, courselink::$validimportmappingfields)) {
                    unset($settings->importfieldmapping[$ecsfield]);
                }
                if (!$moodlefield) {
                    $moodlefield = null;
                } else if (!in_array($moodlefield, $possible)) {
                    $current = $this->get_import_mappings(); // Retrieve the current, or the default.
                    $settings->importfieldmapping[$ecsfield] = $current[$ecsfield];
                }
            }
            ksort($settings->importfieldmapping);
            if ($this->importfieldmapping) {
                ksort($this->importfieldmapping);
                if ($settings->importfieldmapping == $this->importfieldmapping) {
                    unset($settings->importfieldmapping); // Unchanged.
                }
            }
        }

        if (isset($settings->import) || isset($settings->importtype)) {
            // About to change import or import type.
            $newimport = isset($settings->import) ? $settings->import : $this->import;
            $newimporttype = isset($settings->importtype) ? $settings->importtype : $this->importtype;

            if ($newimport && $newimporttype == self::IMPORT_CMS) {
                // We will now be importing as type IMPORT_CMS => check there isn't already a CMS participant.
                if ($cms = self::get_cms_participant(true)) {
                    throw new coding_exception("There is already a CMS configured: {$cms->displayname}");
                }
            }
        }

        // Clean the settings - make sure only expected values exist.
        $updateneeded = false;
        foreach ($settings as $name => $value) {
            if (in_array($name, self::$validsettings)) {
                $updateneeded = true;
            } else {
                unset($settings->$name);
            }
        }

        if ($updateneeded) {
            $settings->id = $this->recordid;
            $this->save_to_database($settings);
            $this->set_settings($settings);

            // Import state changed - need to update all course links.
            if (isset($settings->import)) {
                if ($settings->import) {
                    courselink::refresh_from_participant($this->ecsid, $this->mid);
                } else {
                    // No longer importing course links.
                    courselink::delete_mid_courselinks($this->mid);
                }
            }

            if (isset($settings->export)) {
                if ($settings->export) {
                    // Nothing to do here - will be updated at next cron.
                } else {
                    export::delete_mid_exports($this);
                }
            }
        }
    }

    /**
     * Check to see if there are any problems with the settings that are about to be saved and return an
     * error message if that is the case
     * @param $settings
     * @return string
     */
    public function check_settings($settings) {
        if ($settings->import && $settings->importtype == self::IMPORT_CMS) {
            /** @var $cms participantsettings */
            if ($cms = self::get_cms_participant(true)) {
                if ($cms->get_ecs_id() != $this->get_ecs_id() || $cms->get_mid() != $this->get_mid()) {
                    $data = (object)array(
                        'newcms' => $this->get_displayname(),
                        'currcms' => $cms->get_displayname()
                    );
                    return get_string('alreadycms', 'local_campusconnect', $data);
                }
            }
        }

        return '';
    }

    /**
     * Check to see if any of the settings changes will result in data loss and return an html fragment
     * to notify the user. If no data loss will occur, returns null.
     * @param $settings
     * @return mixed string | null
     */
    public function get_confirm_message($settings) {
        global $DB;
        $ret = array();
        if (isset($settings->export) && !$settings->export && $this->export) {
            // Disabling export - check to see if there are any exports that would be deleted.
            $exports = export::list_all_exports($this->ecsid, $this->mid);
            if (!empty($exports)) {
                $msg = html_writer::tag('p', get_string('warningexports', 'local_campusconnect', $this->displayname));
                foreach ($exports as $export) {
                    $course = $DB->get_record('course', array('id' => $export->get_courseid()), 'id, fullname');
                    if ($course) {
                        $courseurl = new moodle_url('/course/view.php', array('id' => $course->id));
                        $msg .= html_writer::link($courseurl, format_string($course->fullname));
                        $msg .= html_writer::empty_tag('br');
                    }
                }
                $ret[] = $msg;
            }
        }

        $changeimporttype = isset($settings->importtype) && ($settings->importtype != $this->importtype);
        $disableimport = isset($settings->import) && !$settings->import && $this->import;
        $disableclimport = $this->importtype == self::IMPORT_LINK && ($disableimport || $changeimporttype);
        $disablecmsimport = $this->importtype == self::IMPORT_CMS && ($disableimport || $changeimporttype);
        if ($disableclimport) {
            // Disabling course link import - check to see if there are any currently imported courses that would be deleted.
            $imports = courselink::list_links($this->ecsid, $this->mid);
            if (!empty($imports)) {
                $msg = html_writer::tag('p', get_string('warningimports', 'local_campusconnect', $this->displayname));
                foreach ($imports as $import) {
                    $msg .= html_writer::link($import->get_url(), format_string($import->get_title()));
                    $msg .= html_writer::empty_tag('br');
                }
                $ret[] = $msg;
            }
        }

        if ($disablecmsimport) {
            // TODO davo - list the changes that would result from disabling the CMS import type.
        }

        if (empty($ret)) {
            return null;
        }

        return implode('<br/>', $ret);
    }

    protected function set_settings($settings) {
        foreach (self::$validsettings as $setting) {
            if (isset($settings->$setting)) {
                $this->$setting = $settings->$setting;
            }
        }
        if (isset($settings->id)) {
            // The settings came from the database.
            $this->recordid = $settings->id;
            $dbsettings = array('mid', 'ecsid', 'pid', 'displayname', 'active', 'orgabbr');
            foreach ($dbsettings as $fieldname) {
                if (isset($settings->$fieldname)) {
                    $this->$fieldname = $settings->$fieldname;
                }
            }
        }
        if ($this->exportfields) {
            if (!is_array($this->exportfields)) {
                $this->exportfields = explode(',', $this->exportfields);
            }
        } else if ($this->exportfields !== null) {
            $this->exportfields = array();
        }
        if ($this->exportfieldmapping) {
            if (!is_array($this->exportfieldmapping)) {
                $this->exportfieldmapping = unserialize($this->exportfieldmapping);
            }
        } else if ($this->exportfieldmapping !== null) {
            $this->exportfieldmapping = array();
        }
        if ($this->importfieldmapping) {
            if (!is_array($this->importfieldmapping)) {
                $this->importfieldmapping = unserialize($this->importfieldmapping);
            }
        } else if ($this->importfieldmapping !== null) {
            $this->importfieldmapping = array();
        }
    }

    protected function save_to_database($settings) {
        global $DB;
        $ins = clone $settings;
        if (isset($ins->exportfields)) {
            $ins->exportfields = implode(',', $ins->exportfields);
        }
        if (!empty($ins->exportfieldmapping)) {
            $ins->exportfieldmapping = serialize($ins->exportfieldmapping);
        }
        if (!empty($ins->importfieldmapping)) {
            $ins->importfieldmapping = serialize($ins->importfieldmapping);
        }

        if (!empty($settings->id)) {
            $DB->update_record('local_campusconnect_part', $ins);
            return $settings->id;
        }
        return $DB->insert_record('local_campusconnect_part', $ins);
    }

    public function get_settings() {
        $ret = new stdClass();
        foreach (self::$validsettings as $setting) {
            $ret->$setting = $this->$setting;
        }
        foreach (self::$ecssettings as $setting) {
            $ret->$setting = $this->$setting;
        }
        return $ret;
    }

    public function delete_settings() {
        global $DB;

        $DB->delete_records('local_campusconnect_part', array('id' => $this->recordid));
        courselink::delete_mid_courselinks($this->mid);
    }

    /**
     * Check the participant is part of an active ECS.
     * @return bool
     */
    public function is_active() {
        return ecssettings::is_active_ecs($this->ecsid) && $this->active;
    }

    /**
     * Participant found during the last check of the ECS server, so mark as currently active (if not already).
     */
    public function set_active() {
        global $DB;
        if (!$this->active) {
            $this->active = 1;
            if ($this->recordid) {
                $DB->set_field('local_campusconnect_part', 'active', 1, array('id' => $this->recordid));
            }
        }
    }

    /**
     * Participant was NOT found during the last check of the ECS server, so mark as inactive.
     */
    public function set_inactive() {
        global $DB;
        if ($this->active) {
            $this->active = 0;
            if ($this->recordid) {
                $DB->set_field('local_campusconnect_part', 'active', 0, array('id' => $this->recordid));
            }
        }
    }

    public static function is_custom_field($fieldname) {
        $len = strlen(self::CUSTOM_FIELD_PREFIX);
        if (substr($fieldname, 0, $len) == self::CUSTOM_FIELD_PREFIX) {
            return substr($fieldname, $len);
        }
        return false;
    }

    public function map_export_data($user) {
        global $SITE;

        // Some mappings are hard-coded.
        $ret = array(
            'ecs_firstname' => $user->firstname,
            'ecs_lastname' => $user->lastname,
            'ecs_institution' => $SITE->shortname,
        );

        if ($this->is_legacy_export()) {
            // Support sending of legacy, hard-coded mappings.
            $ret['ecs_login'] = $user->username;
            $ret['ecs_email'] = $user->email;
            if (courselink::INCLUDE_LEGACY_PARAMS) {
                $ret['ecs_uid_hash'] = self::get_uid_prefix().$user->id;
            } else {
                $ret['ecs_uid'] = $user->id;
            }

        } else {
            // Map the selected fields.
            $mapping = $this->get_export_mappings();
            $toexport = $this->get_export_fields();
            $possiblefields = self::get_possible_export_fields();
            foreach ($mapping as $ecs => $moodle) {
                if (!in_array($ecs, $toexport)) {
                    continue;
                }
                if (!$moodle && !in_array($moodle, $possiblefields)) {
                    $ret[$ecs] = ''; // No (valid) mapping specified.
                } else if ($fieldname = self::is_custom_field($moodle)) {
                    if (isset($user->profile[$fieldname])) {
                        $ret[$ecs] = $user->profile[$fieldname]; // Custom profile field.
                    } else {
                        $ret[$ecs] = '';
                    }
                } else {
                    $ret[$ecs] = $user->$moodle;
                }
            }
            $ret[courselink::PERSON_ID_TYPE] = $this->personuidtype;
        }

        // UID needs the site identifier adding to it.
        if (!empty($ret[courselink::PERSON_UID])) {
            $prefix = self::get_uid_prefix();
            $ret[courselink::PERSON_UID] = $prefix.$ret[courselink::PERSON_UID];
        }

        return $ret;
    }

    public static function get_uid_prefix() {
        global $CFG;
        $siteid = substr(sha1($CFG->wwwroot), 0, 8);
        return 'moodle_'.$siteid.'_usr_';
    }

    public static function is_legacy($ecsdata) {
        return !isset($ecsdata[courselink::PERSON_ID_TYPE]);
    }

    public function map_import_data($ecsdata) {

        // Some mappings are hard-coded.
        $ret = (object)array(
            'firstname' => $ecsdata['ecs_firstname'],
            'lastname' => $ecsdata['ecs_lastname'],
        );

        if (self::is_legacy($ecsdata)) {
            // Email is the only other value mapped by the legacy params.
            $ret->email = $ecsdata['ecs_email'];
        } else {

            // Non-legacy - do the full mapping.
            $mapping = $this->get_import_mappings();
            $possiblefields = self::get_possible_import_fields();
            foreach ($mapping as $ecs => $moodle) {
                if (!$moodle) {
                    continue; // Not mapped onto anything in Moodle.
                }
                if (!in_array($moodle, $possiblefields)) {
                    continue; // Not mapped onto anything valid in Moodle.
                }
                if (isset($ecsdata[$ecs])) {
                    $ret->$moodle = $ecsdata[$ecs];
                }
            }
        }

        return $ret;
    }

    /**
     * Get a list of all the participants in all the ECS that we are able to
     * export courses to
     * @return participantsettings[] indexed by ecsid_mid
     */
    public static function list_potential_export_participants() {
        global $DB;
        $parts = $DB->get_records('local_campusconnect_part', array('export' => 1), 'displayname');
        $ret = array();
        foreach ($parts as $part) {
            $participant = new participantsettings($part);
            if ($participant->is_active()) {
                $ret[$participant->get_identifier()] = $participant;
            }
        }
        return $ret;
    }

    /**
     * Load all the communities we are a member of (including participant lists) from
     * the given ECS
     * @param ecssettings $ecssettings - the ECS to connect to
     * @return community[] details of the communities
     */
    public static function load_communities(ecssettings $ecssettings) {
        global $DB;

        $connect = new connect($ecssettings);
        $communities = $connect->get_memberships();
        $ecsid = $ecssettings->get_id();

        $missingmids = $DB->get_records_menu('local_campusconnect_part', array('active' => 1, 'ecsid' => $ecsid), '', 'mid, id');
        $resp = array();
        foreach ($communities as $community) {
            $comm = new community($ecsid, $community->community->name, $community->community->description);
            foreach ($community->participants as $participant) {
                $mid = $participant->mid;
                $participant->communityname = $comm->name;
                $part = new participantsettings($ecsid, $mid, $participant);
                $part->set_active();
                $comm->add_participant($part);
                unset($missingmids[$mid]);
            }

            $resp[$community->community->cid] = $comm;
        }

        // Deactivate any participants that were not found in the communities.
        foreach ($missingmids as $mid => $recordid) {
            $part = new participantsettings($ecsid, $mid);
            $part->set_inactive();
        }

        return $resp;
    }

    /**
     * Delete the settings for the participants in this ECS (also deletes
     * and course links created by these participants)
     * @param int $ecsid
     */
    public static function delete_ecs_participant_settings($ecsid) {
        global $DB;

        $parts = $DB->get_records('local_campusconnect_part', array('ecsid' => $ecsid));
        foreach ($parts as $participant) {
            courselink::delete_mid_courselinks($participant->mid);
        }
        $DB->delete_records('local_campusconnect_part', array('ecsid' => $ecsid));
    }

    /**
     * Returns the participant that has import type CMS
     * @param bool $skipcache do not use the cached value (useful when updating settings)
     * @throws coding_exception
     * @return mixed participantsettings | false
     */
    public static function get_cms_participant($skipcache = false) {
        global $DB;

        static $participant = null;

        if (is_null($participant) || $skipcache) {
            // Find the participant with the import type set to IMPORT_CMS (ignoring disabled ECS).
            $sql = "SELECT p.*
                      FROM {local_campusconnect_part} p
                      JOIN {local_campusconnect_ecs} e ON p.ecsid = e.id
                     WHERE e.enabled = 1 AND p.active = 1 AND p.import = 1 AND p.importtype = :importtype";
            $participant = $DB->get_records_sql($sql, array('importtype' => self::IMPORT_CMS));
            if (count($participant) > 1) {
                throw new coding_exception('There should only ever be one participant set to IMPORT_CMS');
            }

            $participant = reset($participant);
            if ($participant) {
                $participant = new participantsettings($participant);
            }
        }

        return $participant;
    }

    protected static function update_pidtomid() {
        global $DB;
        self::$pidtomid = array();
        foreach ($DB->get_records('local_campusconnect_part', null, '', 'id, ecsid, mid, pid') as $part) {
            if (!$part->pid) {
                continue;
            }
            if (!isset(self::$pidtomid[$part->ecsid])) {
                self::$pidtomid[$part->ecsid] = array();
            }
            if (!isset(self::$pidtomid[$part->ecsid][$part->pid])) {
                self::$pidtomid[$part->ecsid][$part->pid] = array();
            }
            self::$pidtomid[$part->ecsid][$part->pid][] = $part->mid;
        }
    }

    /**
     * Given an ECSID and PID, returns the MID[s] that participant is known by from the point of view of this VLE (if any).
     *
     * @param int $ecsid
     * @param int $pid
     * @return int[]
     */
    public static function get_mids_from_pid($ecsid, $pid) {
        if (self::$pidtomid === null) {
            self::update_pidtomid();
        }
        if (isset(self::$pidtomid[$ecsid][$pid])) {
            return self::$pidtomid[$ecsid][$pid];
        }
        return array();
    }

    public static function get_mids_from_pids($ecsid, $pids) {
        if (self::$pidtomid === null) {
            self::update_pidtomid();
        }
        $mids = array();
        $pids = explode(',', $pids);
        foreach ($pids as $pid) {
            $pid = explode('_', $pid);
            if ($pid[0] != $ecsid) {
                continue; // This PID is for a different ECS.
            }
            if (!empty(self::$pidtomid[$ecsid][$pid[1]])) {
                $mids = array_merge(self::$pidtomid[$ecsid][$pid[1]], $mids);
            }
        }
        return array_unique($mids);
    }

    /**
     * @param $ecsid
     * @param $pid
     * @return int|false
     */
    public static function get_org_abbr($ecsid, $pid) {
        global $DB;
        $orgabbr = $DB->get_field('local_campusconnect_part', 'orgabbr', ['ecsid' => $ecsid, 'pid' => $pid]);
        if (!$orgabbr || ($orgabbr == 'n/a')) {
            return null;
        }
        return $orgabbr;
    }
}