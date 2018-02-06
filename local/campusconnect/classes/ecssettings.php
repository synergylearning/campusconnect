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
 * Configuration settings for connecting to an ECS
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class ecssettings {

    const AUTH_NONE = 1; // Development only - direct connection to ECS server.
    const AUTH_HTTP = 2; // Basic HTTP authentication.
    const AUTH_CERTIFICATE = 3; // Certificate based authentication.

    // Connection settings.
    protected $url = '';
    protected $auth = self::AUTH_CERTIFICATE;
    protected $ecsauth = '';
    protected $httpuser = '';
    protected $httppass = '';
    protected $cacertpath = '';
    protected $certpath = '';
    protected $keypath = '';
    protected $keypass = '';

    // Settings for incoming data.
    protected $crontime = 60;
    protected $lastcron = 0;
    protected $importcategory = null;
    protected $importrole = 'student';
    protected $importperiod = 6;

    // Notification details.
    protected $notifyusers = '';
    protected $notifycontent = '';
    protected $notifycourses = '';

    // Misc settings.
    protected $recordid = null;
    protected $enabled = true;
    protected $name = '';

    // Used to validate incoming settings.
    protected $validsettings = array(
        'recordid' => 'id',
        'enabled' => 'enabled',
        'name' => 'name',
        'url' => 'url',
        'auth' => 'auth',
        'ecsauth' => 'ecsauth',
        'httpuser' => 'httpuser',
        'httppass' => 'httppass',
        'cacertpath' => 'cacertpath',
        'certpath' => 'certpath',
        'keypath' => 'keypath',
        'keypass' => 'keypass',
        'crontime' => 'crontime',
        'importcategory' => 'importcategory',
        'importrole' => 'importrole',
        'importperiod' => 'importperiod',
        'notifyusers' => 'notifyusers',
        'notifycontent' => 'notifycontent',
        'notifycourses' => 'notifycourses'
    );

    protected static $activeecs = null;

    /**
     * Initialise a settings object
     * @param int $ecsid optional - the ID of the ECS to load settings for
     */
    public function __construct($ecsid = null) {
        // Load the settings, if an ECS ID has been specified.
        if ($ecsid) {
            $this->load_settings($ecsid);
        }
    }

    public static function list_ecs($onlyenabled = true) {
        global $DB;
        $params = array();
        if ($onlyenabled) {
            $params['enabled'] = 1;
        }
        return $DB->get_records_menu('local_campusconnect_ecs', $params, 'name, id', 'id, name');
    }

    /**
     * Check if the given ECS is currently active.
     * @param int $ecsid
     * @return bool
     */
    public static function is_active_ecs($ecsid) {
        if (self::$activeecs === null) {
            self::$activeecs = array_keys(self::list_ecs(true));
        }
        return in_array($ecsid, self::$activeecs);
    }

    public function get_id() {
        return $this->recordid;
    }

    public function is_enabled() {
        return $this->enabled;
    }

    public function get_name() {
        return $this->name;
    }

    public function get_url() {
        return $this->url;
    }

    public function get_auth_type() {
        return $this->auth;
    }

    public function get_ecs_auth() {
        if ($this->get_auth_type() != self::AUTH_NONE) {
            throw new coding_exception('get_ecs_auth only valid when using no authentication');
        }
        return $this->ecsauth;
    }

    public function get_http_user() {
        if ($this->get_auth_type() != self::AUTH_HTTP) {
            throw new coding_exception('get_http_user only valid when using http authentication');
        }
        return $this->httpuser;
    }

    public function get_http_password() {
        if ($this->get_auth_type() != self::AUTH_HTTP) {
            throw new coding_exception('get_http_password only valid when using http authentication');
        }
        return $this->httppass;
    }

    public function get_ca_cert_path() {
        if ($this->get_auth_type() != self::AUTH_CERTIFICATE) {
            throw new coding_exception('get_ca_cert_path only valid when using certificate authentication');
        }
        return $this->cacertpath;
    }

    public function get_client_cert_path() {
        if ($this->get_auth_type() != self::AUTH_CERTIFICATE) {
            throw new coding_exception('get_client_cert_path only valid when using certificate authentication');
        }
        return $this->certpath;
    }

    public function get_key_path() {
        if ($this->get_auth_type() != self::AUTH_CERTIFICATE) {
            throw new coding_exception('get_key_path only valid when using certificate authentication');
        }
        return $this->keypath;
    }

    public function get_key_pass() {
        if ($this->get_auth_type() != self::AUTH_CERTIFICATE) {
            throw new coding_exception('get_key_pass only valid when using certificate authentication');
        }
        return $this->keypass;
    }

    public function get_import_category() {
        return $this->importcategory;
    }

    public function get_import_role() {
        return $this->importrole;
    }

    public function get_import_period() {
        return $this->importperiod;
    }

    public function get_notify_users() {
        return explode(',', $this->notifyusers);
    }

    public function get_notify_content() {
        return explode(',', $this->notifycontent);
    }

    public function get_notify_courses() {
        return explode(',', $this->notifycourses);
    }

    public function get_certificate_expiry() {
        if ($this->auth != self::AUTH_CERTIFICATE) {
            return '';
        }
        if (empty($this->certpath)) {
            return '';
        }
        $certinfo = openssl_x509_parse(file_get_contents($this->certpath));
        return userdate($certinfo['validTo_time_t'], get_string('strftimedate'));
    }

    protected function load_settings($ecsid) {
        global $DB;

        $settings = $DB->get_record('local_campusconnect_ecs', array('id' => $ecsid), '*', MUST_EXIST);
        $this->set_settings($settings);
    }

    protected function set_settings($settings) {
        foreach ($this->validsettings as $localname => $dbname) {
            if (isset($settings->$dbname)) {
                $this->$localname = $settings->$dbname;
            }
        }
        if (isset($settings->lastcron)) {
            $this->lastcron = $settings->lastcron; // Not part of validsettings, as should never be set via the UI.
        }
    }

    public function save_settings($settings) {
        global $DB;

        $settings = (array)$settings; // Avoid updating passed-in objects.
        $settings = (object)$settings;

        // Clean the settings - make sure only expected values exist.
        foreach ($settings as $setting => $value) {
            if (!array_key_exists($setting, $this->validsettings)) {
                unset($settings->$setting);
            }
        }

        // Check the settings are valid.
        if (empty($settings->url)) {
            if (empty($this->url)) {
                throw new coding_exception("campusconnect_ecssettings - missing 'url' field");
            }
        } else {
            $scheme = parse_url($settings->url, PHP_URL_SCHEME);
            if ($scheme != 'http' && $scheme != 'https') {
                throw new coding_exception("campusconnect_ecssettings - URL must start 'http://' or 'https://'");
            }
        }

        if (isset($settings->auth)) {
            $auth = $settings->auth;
        } else {
            if (empty($this->auth)) {
                throw new coding_exception('campusconnect_ecssettings - missing \'auth\' field');
            }
            $auth = $this->auth;
        }

        switch ($auth) {
            case self::AUTH_NONE:
                if (empty($settings->ecsauth) && empty($this->ecsauth)) {
                    throw new coding_exception("campusconnect_ecssettings - auth method 'AUTH_NONE' requires an 'ecsauth' value");
                }
                break;
            case self::AUTH_HTTP:
                $requiredfields = array('httpuser', 'httppass');
                foreach ($requiredfields as $required) {
                    if (empty($settings->$required) && empty($this->$required)) {
                        throw new coding_exception("campusconnect_ecssettings - auth method 'AUTH_HTTP' requires ".
                                                   "a '$required' value");
                    }
                }
                break;
            case self::AUTH_CERTIFICATE:
                $requiredfields = array('cacertpath', 'certpath', 'keypath', 'keypass');
                foreach ($requiredfields as $required) {
                    if (empty($settings->$required) && empty($this->$required)) {
                        throw new coding_exception("campusconnect_ecssettings - auth method 'AUTH_CERTIFICATE' requires ".
                                                   "a '$required' value");
                    }
                }
                break;
            default:
                throw new coding_exception("campusconnect_ecssettings - invalid 'auth' value: $auth");
        }

        if (isset($settings->crontime) && $settings->crontime < 0) {
            throw new coding_exception("campusconnect_ecssettings - invalid crontime: $settings->crontime");
        }

        if (isset($settings->importcategory)) {
            if ($settings->importcategory != $this->importcategory) {
                if (!$DB->record_exists('course_categories', array('id' => $settings->importcategory))) {
                    throw new coding_exception("campusconnect_ecssettings - non-existent category ID: $settings->importcategory");
                }
            }
        } else if (empty($this->importcategory)) {
            throw new coding_exception("campusconnect_ecssettings - missing 'importcategory' field");
        }

        if (isset($settings->importrole)) {
            if ($settings->importrole != $this->importrole) {
                if (!$DB->record_exists('role', array('shortname' => $settings->importrole))) {
                    throw new coding_exception("campusconnect_ecssettings - non-existent role shortname: $settings->importrole");
                }
            }
        } else if (empty($this->importrole)) {
            throw new coding_exception("campusconnect_ecssettings - missing 'importrole' field");
        }

        // Remove any spaces from the notify lists.
        if (isset($settings->notifyusers)) {
            $notify = explode(',', $settings->notifyusers);
            $notify = array_map('trim', $notify);
            $settings->notifyusers = implode(',', $notify);
        }

        if (isset($settings->notifycontent)) {
            $notify = explode(',', $settings->notifycontent);
            $notify = array_map('trim', $notify);
            $settings->notifycontent = implode(',', $notify);
        }

        if (isset($settings->notifycourses)) {
            $notify = explode(',', $settings->notifycourses);
            $notify = array_map('trim', $notify);
            $settings->notifycourses = implode(',', $notify);
        }

        // Save the settings.
        if (is_null($this->recordid)) {
            // Newly created ECS connection.
            $settings->id = $DB->insert_record('local_campusconnect_ecs', $settings);
        } else {
            $settings->id = $this->recordid;
            $DB->update_record('local_campusconnect_ecs', $settings);
        }

        // Update the local settings.
        $this->set_settings($settings);
    }

    public function get_settings() {
        $ret = new stdClass();
        foreach ($this->validsettings as $localname => $dbname) {
            $ret->$localname = $this->$localname;
        }
        return $ret;
    }

    public function delete($force = false) {
        global $DB;

        if (!is_null($this->recordid)) {
            metadata::delete_ecs_metadata_mappings($this->recordid);
            participantsettings::delete_ecs_participant_settings($this->recordid);
            export::delete_ecs_exports($this->recordid, $force);
            $DB->delete_records('local_campusconnect_ecs', array('id' => $this->recordid));
            $this->recordid = null;
            $this->auth = -1;
        }
    }

    /**
     * Check if it is time to run a cron update for this ECS
     * @return bool true if time for cron script to run
     */
    public function time_for_cron() {
        if ($this->crontime == 0) {
            return false;
        }
        return (($this->lastcron + $this->crontime) < time());
    }

    /**
     * Save the current time as the lastcron time
     */
    public function update_last_cron() {
        global $DB;

        $lastcron = time();
        if (!is_null($this->recordid)) {
            $DB->set_field('local_campusconnect_ecs', 'lastcron', $lastcron, array('id' => $this->recordid));
        }
        $this->lastcron = $lastcron;
    }
}