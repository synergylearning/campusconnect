<?php
/**
 * @package    campusconnect
 * @copyright  2012 Synergy Learning
 */

use local_campusconnect\connect;
use local_campusconnect\connect_exception;
use local_campusconnect\courselink;
use local_campusconnect\ecssettings;
use local_campusconnect\export;
use local_campusconnect\log;
use local_campusconnect\participantsettings;

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}
global $CFG;
require_once($CFG->libdir.'/authlib.php');

/**
 * CampusConnect authentication plugin.
 */
class auth_plugin_campusconnect extends auth_plugin_base {

    public static $authenticateduser;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'campusconnect';
    }

    /**
     * Authenticates user against ECS
     * Returns true if ECS confirms user is authenticated.
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     * @return bool Authentication success or failure.
     */
    function user_login($username, $password) {
        if (isset(self::$authenticateduser)
            && is_object(self::$authenticateduser)
            && isset(self::$authenticateduser->id)
        ) {
            return true;
        }
        return false;
    }

    function prevent_local_passwords() {
        return false;
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal() {
        return false;
    }

    /**
     * Hook for overriding behaviour of login page.
     * This method is called from login/index.php page for all enabled auth plugins.
     *
     */
    function loginpage_hook() {
        global $SESSION, $CFG, $DB;

        self::log("\n\n====Login required - checking for CampusConnect authentication====");

        if (!isset($SESSION) || !isset($SESSION->wantsurl)) {
            self::log("No destination URL");
            return;
        }

        if (!$userdetails = self::authenticate_from_url($SESSION->wantsurl)) {
            return;
        }

        self::log("Authentication successful");

        // If user does not exist, create them.
        if (!$ccuser = get_complete_user_data('username', $userdetails->username)) {
            self::log("Creating a new user account with username {$userdetails->username}");
            $ccuser = new stdClass();
            foreach ($userdetails as $field => $value) {
                if (!participantsettings::is_custom_field($field)) { // Custom profile fields dealt with later.
                    $ccuser->$field = $value;
                }
            }
            $ccuser->modified = time();
            $ccuser->confirmed = 1;
            $ccuser->auth = $this->authtype;
            $ccuser->mnethostid = $CFG->mnet_localhost_id;
            $ccuser->lang = $CFG->lang;
            $ccuser->timecreated = time();
            if (!$id = $DB->insert_record('user', $ccuser)) {
                print_error('errorcreatinguser', 'auth_campusconnect');
            }
            $ccuser = get_complete_user_data('id', $id);
        }

        $systemcontext = context_system::instance();
        $ecssettings = new ecssettings($userdetails->ecsid);
        $defaultrole = $ecssettings->get_import_role();
        $defaultroleid = $DB->get_field('role', 'id', array('shortname' => $defaultrole));
        role_assign($defaultroleid, $ccuser->id, $systemcontext->id);

        if (isset($userdetails->ecsid)) {
            unset ($userdetails->ecsid);
        }
        if (isset($ccuser->ecsid)) {
            unset ($ccuser->ecsid);
        }

        self::log("User account details:");
        self::log_print_r($ccuser);

        // Do we need to update details?
        $needupdate = false;
        foreach ($userdetails as $field => $value) {
            if ($fieldname = participantsettings::is_custom_field($field)) {
                // Safe to check $user->profile, as we've used 'get_complete_user_data' above.
                if (!isset($ccuser->profile[$fieldname]) || $ccuser->profile[$fieldname] != $value) {
                    if ($fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => $fieldname))) {
                        if ($existing = $DB->get_record('user_info_data', array('fieldid' => $fieldid, 'userid' => $ccuser->id))) {
                            $upd = (object)array(
                                'id' => $existing->id,
                                'data' => $value,
                            );
                            $DB->update_record('user_info_data', $upd);
                        } else {
                            $ins = (object)array(
                                'userid' => $ccuser->id,
                                'fieldid' => $fieldid,
                                'data' => $value,
                            );
                            $DB->insert_record('user_info_data', $ins);
                        }
                        $ccuser->profile[$fieldname] = $value;
                    }
                }
            } else if ($ccuser->$field != $value) {
                $ccuser->$field = $value;
                $needupdate = true;
            }
        }
        if ($needupdate) {
            self::log("Updating user account details:");
            self::log_print_r($ccuser);
            $DB->update_record('user', $ccuser);
        }

        // Let index.php know that user is authenticated.
        global $frm, $user;
        $frm = (object)array('username' => $ccuser->username, 'password' => '');
        $user = clone $ccuser;
        self::$authenticateduser = clone $ccuser;
    }

    /**
     * Given the URL that was called, authenticate the user and return the details of the
     * user to create / update.
     *
     * @param string $url
     * @return null|object null if the authentication failed, otherwise the user details
     */
    public static function authenticate_from_url($url) {
        if (!$params = self::extract_url_params($url)) {
            return null; // No params to process.
        }

        if (!$courseurl = self::check_course_url($url, $params)) {
            return null; // URL does not match that for a Moodle course.
        }

        self::log("Destination URL: {$url}");

        if (!$authinfo = self::authenticate_from_params($url, $params, $courseurl)) {
            return null; // Authentication failed.
        }

        return self::get_user_details($authinfo->ecsid, $authinfo->pid, $authinfo->participant, $params);
    }

    protected static function extract_url_params($url) {
        $urlparse = parse_url($url);
        if (!is_array($urlparse) || !isset($urlparse['query'])) {
            self::log("Destination URL lacks query string");
            return null;
        }
        $urlquery = str_replace('&amp;', '&', $urlparse['query']);
        $queryparams = explode('&', $urlquery);
        $paramassoc = array();
        foreach ($queryparams as $paramval) {
            $split = explode('=', $paramval);
            if (count($split) < 2) {
                continue;
            }
            $paramassoc[$split[0]] = urldecode($split[1]);
        }
        return $paramassoc;
    }

    protected static function check_course_url($url, $params) {
        if (!isset($params['id'])) {
            self::log("No courseid in the destination URL");
            return null; // URL didn't include a course ID.
        }
        $courseurl = new moodle_url('/course/view.php', array('id' => $params['id']));
        $courseurl = $courseurl->out(); // Legacy direct course URL.
        $courseviewurl = new moodle_url('/local/campusconnect/viewcourse.php', array('id' => $params['id']));
        $courseviewurl = $courseviewurl->out(); // Newer courselink destination URL.
        if (substr_compare($url, $courseurl, 0, strlen($courseurl)) !== 0) {
            $courseurl = $courseviewurl;
            if (substr_compare($url, $courseurl, 0, strlen($courseurl)) !== 0) {
                self::log("Destination URL is not a Moodle course");
                return null; // URL didn't match a Moodle course URL.
            }
        }
        return $courseurl;
    }

    protected static function authenticate_from_params($fullurl, $params, $courseurl) {
        // Extract the hash from the params.
        $hash = null;
        $baseurl = null;
        if (!empty($params['ecs_hash'])) {
            // Prefer the use of 'ecs_hash'.
            $hash = $params['ecs_hash'];
            self::log("ecs_hash found: {$hash}");
        } else if (!empty($params['ecs_hash_url'])) {
            // Fall back on the use of 'ecs_hash_url'.
            $hashurl = $params['ecs_hash_url'];
            self::log("ecs_hash_url found: {$params['ecs_hash_url']}");

            $matches = array();
            if (!preg_match('|(.*)/sys/auths/(.*)|', $hashurl, $matches)) {
                self::log("Unable to parse ecs_hash_url");
                return null; // Not able to parse the 'ecs_hash_url' successfully.
            }
            $baseurl = $matches[1];
            $hash = $matches[2];
        } else {
            self::log("Neither ecs_hash nor ecs_hash_url included in destination URL");
            return null;
        }

        // Check the authentication.
        return self::check_authentication($fullurl, $baseurl, $hash, $courseurl, $params);
    }

    /**
     * Check against each ECS to see if any of them can authenticate the user.
     *
     * @param string $fullurl the full requested URL the user followed
     * @param string|null $baseurl the URL from ecs_hash_url (or null, if using ecs_hash instead)
     * @param string $hash the authentication hash value
     * @param string $courseurl the courselink URL that was exported
     * @param array $params the full set of params from the URL
     * @throws moodle_exception
     * @return object|null null if the authentication failed, otherwise it contains:
     *                ecsid: the ECS that authenticated the user,
     *                  pid: the participant the user came from,
     * participant: the \local_campusconnect\participantsettings object related to this participant
     */
    protected static function check_authentication($fullurl, $baseurl, $hash, $courseurl, $params) {
        $authenticatingecs = null;
        $pid = null;
        $participant = null;
        $connecterrors = false;
        $now = time(); // In case of slow connections / debugging, note the time at the start of the loop.

        $ecslist = ecssettings::list_ecs();
        foreach ($ecslist as $ecsid => $ecsname) {
            $settings = new ecssettings($ecsid);
            if ($baseurl) {
                self::log("Comparing hash URL: {$baseurl} with ECS server '$ecsname' ($ecsid): ".$settings->get_url());
            } else {
                self::log("Attempting to authenticate hash against '{$ecsname}' ($ecsid)");
            }
            if (!$baseurl || self::strip_port($settings->get_url()) == self::strip_port($baseurl)) {
                // Found an ECS with matching URL - attempt to authenticate the hash.
                try {
                    $connect = new connect($settings);
                    $auth = $connect->get_auth($hash);
                    self::log("Checking hash against ECS server:");
                    self::log_print_r($auth);
                    if (is_object($auth) && isset($auth->hash) && $auth->hash == $hash) {
                        if (isset($auth->realm)) {
                            if (participantsettings::is_legacy($params)) {
                                $realm = connect::generate_legacy_realm($courseurl, $params);
                            } else {
                                $realm = connect::generate_realm($fullurl);
                            }
                            if ($realm != $auth->realm) {
                                self::log("Locally generated realm: {$realm} does not match auth realm: {$auth->realm}");
                                continue; // Params do not match those when the original hash was generated.
                            }
                        } else {
                            self::log("Realm not included in auth response");
                        }
                        if (isset($auth->sov)) {
                            $sov = strtotime($auth->sov);
                            if ($sov && $sov > $now) {
                                self::log("Start of validation timestamp ({$auth->sov} = {$sov})".
                                          " is after the current time ({$now})");
                                continue;
                            }
                        }
                        if (isset($auth->eov)) {
                            $eov = strtotime($auth->eov);
                            if ($eov && $eov < $now) {
                                self::log("End of validation timestamp ({$auth->eov} = {$eov})".
                                          " is before the current time ({$now})");
                                continue;
                            }
                        }
                        if (!$participant = self::use_authentication_token($ecsid, $auth->mid, $params['id'])) {
                            $connecterrors = false; // Ignore connection errors in this case.
                            self::log("Authentication token is valid, but is from a participant we are not accepting tokens from");
                            break; // Do not check against any other ECS.
                        }
                        $pid = $auth->pid;
                        $authenticatingecs = $ecsid;
                        break;
                    }
                } catch (connect_exception $e) {
                    $connecterrors = true;
                    self::log("Connection error during authentication: ".$e->getMessage());
                }
            }
        }

        // Throw an error only if a connection exception was thrown and the user wasn't authenticated by any (other) ECS.
        if (!$authenticatingecs) {
            self::log("No ECS servers have authenticated the ECS hash");
            if ($connecterrors) {
                throw new moodle_exception('ecserror_subject', 'local_campusconnect');
            }
            return null;
        }

        return (object)array('ecsid' => $authenticatingecs, 'pid' => $pid, 'participant' => $participant);
    }

    protected static function use_authentication_token($ecsid, $mid, $courseid) {
        // Check the participant settings to see if we should be handling tokens from this participant.
        $export = new export($courseid);
        if ($export->should_handle_auth_token($ecsid, $mid)) {
            return $export->get_participant($ecsid, $mid); // We are accepting authentication tokens from this participant.
        }
        // Ignore the token, as we're not handling authentication from that participant.
        return false;
    }

    protected static function get_user_details($ecsid, $pid, participantsettings $participant, $params) {

        list($personidtype, $personid) = self::get_person_id($params);
        if ($personidtype === null) {
            return null; // Required field missing from the params.
        }
        $institution = $params['ecs_institution'];
        $ecsusername = isset($params['ecs_login']) ? $params['ecs_login'] : null;

        $userdetails = $participant->map_import_data($params);
        $userdetails->username = self::username_from_params($institution, $ecsusername, $personidtype, $personid,
                                                            $ecsid, $pid, $participant);

        if (!$userdetails->username) {
            return null; // Something went wrong with getting a username for this user.
        }

        $userdetails->ecsid = $ecsid;

        return $userdetails;
    }

    protected static function get_person_id($params) {
        if (isset($params[courselink::PERSON_ID_TYPE])) {
            // Using newer version.
            $personidtype = $params[courselink::PERSON_ID_TYPE];
            if (!in_array($personidtype, courselink::$validpersontypes)) {
                self::log("Unknown ecs_person_id_type: {$personidtype}");
                return array(null, null);
            }
            if (empty($params[$personidtype])) {
                self::log("Specified ecs_person_id_type: {$personidtype} not found in user details");
                return array(null, null);
            }
            return array($personidtype, $params[$personidtype]);

        } else {
            // Using legacy params.
            if (!empty($params['ecs_uid'])) {
                $uid = $params['ecs_uid'];
            } else if (!empty($params['ecs_uid_hash'])) {
                $uid = $params['ecs_uid_hash'];
            } else {
                self::log("Neither ecs_uid nor ecs_uid_hash found in destination URL");
                return array(null, null);
            }
            return array(courselink::PERSON_UID, $uid);
        }
    }

    /**
     * Return the given URL with the port number removed.
     * @param $url
     * @return string
     */
    public static function strip_port($url) {
        $parts = parse_url($url);
        $wantedparts = array('scheme', 'host', 'path', 'query');
        $ret = '';
        foreach ($wantedparts as $part) {
            if (array_key_exists($part, $parts)) {
                $ret .= $parts[$part];
                if ($part == 'scheme') {
                    $ret .= '://';
                }
            }
        }
        return $ret;
    }

    /**
     * Logout - check if user is enrolled in any course, if not, delete
     *
     */
    function prelogout_hook() {
        global $USER, $DB;
        if ($USER->auth != $this->authtype) {
            return;
        }

        // Am I currently enrolled?
        if (!empty($USER->enrol['enrolled'])) {
            return;
        }

        if (!$authrecord = $DB->get_record('auth_campusconnect', array('username' => $USER->username))) {
            log::add("auth_campusconnect - user '{$USER->username}' missing record in auth_campusconnect database table");
            return; // Should really exist - log this and move on.
        }

        // Currently not enrolled - have I ever enrolled in anything?
        if ($authrecord->lastenroled) {
            return;
        }

        // OK, delete.
        $user = $DB->get_record('user', array('id' => $USER->id));
        $this->user_dataprotect_delete($user);
    }

    /**
     * Reads user information from ECS and returns it as array()
     *
     * @param string $username username
     * @return mixed array with no magic quotes or false on error
     */
    function get_userinfo($username) {
        return array();
    }

    /**
     * Cron - delete users who timed out and never enrolled,
     * Inactivate users who haven't been active for some time
     * And notify relevant users about users created
     */
    function cron() {
        global $CFG, $DB;

        // Find users whose session should have expired by now and haven't ever enroled in a course.
        $params = array(
            'minaccess' => time() - $CFG->sessiontimeout,
        );
        $sql = "
        SELECT u.id, u.username
          FROM {user} u
          JOIN {auth_campusconnect} ac ON ac.username = u.username
         WHERE u.deleted = 0 AND u.lastaccess < :minaccess
           AND ac.lastenroled IS NULL
        ";
        $deleteusers = $DB->get_records_sql($sql, $params);
        foreach ($deleteusers as $deleteuser) {
            mtrace(get_string('deletinguser', 'auth_campusconnect').': '.$deleteuser->id);
            $this->user_dataprotect_delete($deleteuser);
        }

        // Make users who haven't enrolled in a long time inactive.
        $ecslist = ecssettings::list_ecs();
        $ecsemails = array(); // We'll need it for later.
        foreach ($ecslist as $ecsid => $ecsname) {
            // Get the activation period.
            $settings = new ecssettings($ecsid);
            $monthsago = $settings->get_import_period();
            $month = date('n') - $monthsago;
            $year = date('Y');
            $day = date('j');
            if ($month < 1) {
                $year += floor(($month - 1) / 12);
                $month = $month % 12 + 12;
            }
            $cutoff = mktime(date('H'), date('i'), date('s'), $month, $day, $year);
            $sql = "SELECT u.id, ac.pids
                      FROM {user} u
                      JOIN {auth_campusconnect} ac ON u.username = ac.username
                     WHERE u.suspended = 0 AND u.deleted = 0 AND ac.lastenroled IS NOT NULL AND ac.lastenroled < :cutoff
                   ";
            $params = array('cutoff' => $cutoff);
            $users = $DB->get_records_sql($sql, $params);
            $userids = array();
            foreach ($users as $user) {
                if (self::matches_ecsid($user->pids, $ecsid)) {
                    $userids[] = $user->id;
                }
            }
            if (!empty($userids)) {
                list($usql, $params) = $DB->get_in_or_equal($userids);
                $DB->execute("UPDATE {user}
                                 SET suspended = 1
                               WHERE id $usql", $params);
                // Trigger an event for all users.
                foreach ($DB->get_recordset_list('user', 'id', $userids) as $user) {
                    if ($CFG->version >= 2013111800) {
                        \core\session\manager::kill_user_sessions($user->id);
                    } else {
                        \core\session\manager::kill_user_sessions($user->id);  // Just in case the user is currently logged in.
                    }
                    \core\event\user_updated::create_from_userid($user->id)->trigger();
                }
            }

            // For later.
            $ecsemails[$ecsid] = $settings->get_notify_users();
        }

        // Notify relevant users about new accounts.
        if (!$lastsent = get_config('auth_campusconnect', 'lastnewusersemailsent')) {
            $lastsent = 0;
        }
        $sendupto = time() - 1;
        $params = array(
            'auth' => $this->authtype,
            'lastsent' => $lastsent,
            'sendupto' => $sendupto
        );
        $sql = "
        SELECT u.*, ac.pids
          FROM {user} u
          JOIN {auth_campusconnect} ac ON ac.username = u.username
         WHERE deleted = 0
           AND u.timecreated > :lastsent
           AND u.timecreated <= :sendupto
        ";
        $newusers = $DB->get_records_sql($sql, $params);
        $adminuser = get_admin();
        $notified = array();
        foreach ($newusers as $newuser) {
            $subject = get_string('newusernotifysubject', 'auth_campusconnect');
            $messagetext = get_string('newusernotifybody', 'auth_campusconnect', $newuser);
            $ecsids = self::get_ecsids($newuser->pids);
            foreach ($ecsids as $ecsid) {
                if (!isset($ecslist[$ecsid])) {
                    mtrace(get_string('usernamecantfindecs', 'auth_campusconnect').': '.$newuser->username);
                    continue;
                }
                if (!isset($notified[$ecsid])) {
                    list($in, $params) = $DB->get_in_or_equal($ecsemails[$ecsid]);
                    $notified[$ecsid] = $DB->get_records_select('user', "username $in", $params);
                }
                foreach ($notified[$ecsid] as $recepient) {
                    email_to_user($recepient, $adminuser, $subject, $messagetext);
                }
            }
        }

        set_config('lastnewusersemailsent', $sendupto, 'auth_campusconnect');

        return true;
    }

    protected static function matches_ecsid($pids, $ecsid) {
        return in_array($ecsid, self::get_ecsids($pids));
    }

    protected static function get_ecsids($pids) {
        $pids = explode(',', $pids);
        $ecsids = array();
        foreach ($pids as $pid) {
            $pid = explode('_', $pid);
            $ecsid = intval($pid[0]);
            if ($ecsid && !in_array($ecsid, $ecsids)) {
                $ecsids[] = $ecsid;
            }
        }
        return $ecsids;
    }

    // Local functions.

    /**
     * Look for an existing user who matches the personid + personidtype.
     * Return their username, if they are found.
     * Otherwise generate a new username.
     *
     * @param string $institution
     * @param string|null $ecsusername
     * @param string $personidtype - the type of unique identifier used
     * @param string $personid - the unique identifier from the URL params
     * @param int $ecsid - the ECS that authenticated the user
     * @param int $pid - the ID of the participant the user came from
     * @param participantsettings $participant
     * @internal param string $username
     * @return string
     */
    protected static function username_from_params($institution, $ecsusername, $personidtype, $personid,
                                                   $ecsid, $pid, participantsettings $participant) {
        global $DB;

        // See if we already know about this user.
        if ($ecsuser = $DB->get_record('auth_campusconnect', array('personid' => $personid, 'personidtype' => $personidtype))) {
            self::update_user_pid($ecsuser, $ecsid, $pid);
            return $ecsuser->username; // User has previously authenticated here - just return their previous username.
        }

        $username = null;
        if (!in_array($personidtype, array(courselink::PERSON_UID, courselink::PERSON_LOGIN))) {
            // All other person id types may match an existing user => map the field name, then look for a match.
            $map = $participant->get_import_mappings();
            if (!empty($map[$personidtype])) { // This type is mapped onto a Moodle field.
                $moodlefield = $map[$personidtype];
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
                    $username = $user->username;

                } else if (count($users) > 1) {
                    self::log("More than one user found with {$moodlefield} (mapped from {$personidtype}) set to {$personid}");
                    return null;
                }
            }
        }

        if (!$username) {
            // Not matched an existing user => create a unique username (so a new user will be created).
            if (!$orgabbr = participantsettings::get_org_abbr($ecsid, $pid)) {
                $orgabbr = $institution;
            }
            $username = self::generate_unique_username($orgabbr, $ecsusername);
        }

        // Record the username for future reference.
        $ins = new stdClass();
        $ins->ecsid = $ecsid;
        $ins->pids = "{$ecsid}_{$pid}";
        $ins->personid = $personid;
        $ins->personidtype = $personidtype;
        $ins->username = $username;
        $ins->id = $DB->insert_record('auth_campusconnect', $ins);

        // Return the generated username.
        return $username;
    }

    /**
     * @param string $orgabbr
     * @param string|null $ecsusername
     * @return string
     */
    protected static function generate_unique_username($orgabbr, $ecsusername) {
        global $DB;

        // Generate a new username for this user.
        $prefix = $orgabbr.'_';
        if ($ecsusername) {
            // If a remote username is specified, the generated username will be '{orgabbr}_{username}{NN}'.
            $ecsusername = $prefix.$ecsusername;
            $username = $ecsusername;
        } else {
            // If no remote username is specified, the generated username will just be '{orgabbr}_{NN}'.
            $ecsusername = $prefix;
            $username = $ecsusername.'1';
        }

        // Clean the username, so it only includes valid characters.
        $username = clean_param($username, PARAM_USERNAME);

        // Make sure the username is unique.
        $i = 1;
        while ($DB->record_exists('user', array('username' => $username))) {
            $username = $ecsusername.($i++);
        }
        return $username;
    }

    protected static function update_user_pid($ecsuser, $ecsid, $pid) {
        global $DB;

        $ins = "{$ecsid}_{$pid}";
        if (!$ecsuser->pids) {
            $pids = $ins;
        } else {
            $pids = explode(',', $ecsuser->pids);
            if (in_array($ins, $pids)) {
                return; // Nothing to do here.
            }
            $pids[] = $ins;
            $pids = implode(',', $pids);
        }
        $DB->set_field('auth_campusconnect', 'pids', $pids, array('id' => $ecsuser->id));
    }

    /**
     * Removes all personal information from a user table, deletes the user and all logs
     * @param object $user
     */
    private function user_dataprotect_delete($user) {
        global $DB;

        // Clean personal information.
        $user->email = 'usr'.$user->id.'@'.'usr'.$user->id.'.com';
        $fieldstoclear = array(
            'idnumber', 'firstname', 'lastname',
            'yahoo', 'aim', 'msn', 'phone1', 'phone2', 'institution', 'department',
            'address', 'city', 'country', 'lastip', 'url', 'description', 'imagealt'
        );
        foreach ($fieldstoclear as $fieldname) {
            $user->{$fieldname} = '';
        }
        $DB->update_record('user', $user);

        // Set to deleted.
        delete_user($user);

        // Delete logs.
        $DB->delete_records('log', array('userid' => $user->id));
    }

    protected static function log($msg) {
        log::add($msg, true, false, false);
    }

    protected static function log_print_r($obj) {
        log::add_object($obj, true, false);
    }
}
