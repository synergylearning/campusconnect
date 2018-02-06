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
 * Main connection class for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use coding_exception;

defined('MOODLE_INTERNAL') || die();

class connect {

    /** @var $curlresource resource - curl connection currently being prepared * */
    protected $curlresource = null;
    /** The headers to send in the next request **/
    protected $headers = array();
    /** The settings for connecting to the server **/
    protected $settings = null;
    /** The response headers from the last request **/
    protected $responseheaders = array();
    /** Used to output debug information from the curl request */
    protected $debug = false;

    /** HTTP response codes **/
    const HTTP_CODE_OK = 200;
    const HTTP_CODE_CREATED = 201;
    const HTTP_CODE_NOT_FOUND = 404;

    const SENT = 'sent';
    const RECEIVED = 'received';

    const CONTENT = 'content';
    const TRANSFERDETAILS = 'transferdetails';

    protected static $validsent = array(self::SENT, self::RECEIVED);
    protected static $validtransferdetails = array(self::CONTENT, self::TRANSFERDETAILS);

    /**
     * Construct a new connection
     * @param ecssettings $settings - the settings for connecting to the ECS server
     */
    public function __construct(ecssettings $settings) {
        $this->settings = $settings;
    }

    /**
     * Get the ID of the ECS this is connected to
     * @return int the ECSID (the ID of the record in 'local_campusconnect_ecs')
     */
    public function get_ecs_id() {
        return $this->settings->get_id();
    }

    /**
     * Get the ECS settings object
     * @return ecssettings
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get the category to put new courses into
     * @return int the categoryid
     */
    public function get_import_category() {
        return $this->settings->get_import_category();
    }

    /**
     * Used to turn curl debugging on or off. With debugging on, extra information about the curl response will
     * be output.
     * @param bool $debug true to enable debugging
     */
    public function set_debug($debug) {
        if ($debug) {
            $this->debug = true;
        } else {
            $this->debug = false;
        }
    }

    /**
     * Generate an auth token for a user
     * @param mixed $post the details of the URL the user is connecting to
     * @param int $targetmid the id of the participant the user is connecting to
     * @return string the hash value to append to the url parameters
     */
    public function add_auth($post, $targetmid) {
        if (!is_object($post)) {
            throw new connect_exception('add_auth - expected \'post\' to be an object');
        }

        $poststr = json_encode($post);
        $this->init_connection('/sys/auths');
        $this->set_memberships($targetmid);
        $this->set_postfields($poststr);

        self::log("add_auth - $targetmid");
        self::log($poststr);

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_CREATED)) {
            throw new connect_exception('add_auth - bad response: '.$this->get_status());
        }

        $result = $this->parse_json($result);
        return $result->hash;
    }

    /**
     * Check an auth token
     * @param string $hash value retrieved from the url parameters
     * @return object the authentication details for this connection
     */
    public function get_auth($hash) {
        if (empty($hash)) {
            throw new connect_exception('get_auth - no auth hash given');
        }
        $path = '/sys/auths/'.$hash;
        $this->init_connection($path.'/details');
        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('get_auth - bad response: '.$this->get_status());
        }
        $details = new details($this->parse_json($result));

        $this->init_connection($path);
        $this->set_delete();

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('get_auth - bad response: '.$this->get_status());
        }

        $result = $this->parse_json($result);
        $result->mid = $details->get_sender_mid();

        return $result;
    }

    /**
     * Generates a hash of the URL and userdata which can be used to authenticate the data after calling get_auth
     * Only used when the participant is using 'legacy' settings when following course links.
     *
     * @param string $courseurl
     * @param array $userdata
     * @return string
     */
    public static function generate_legacy_realm($courseurl, $userdata) {
        $str = $courseurl;
        $params = array('ecs_login', 'ecs_firstname', 'ecs_lastname', 'ecs_email', 'ecs_institution', 'ecs_uid');
        foreach ($params as $param) {
            if (!isset($userdata[$param])) {
                if ($param == 'ecs_uid' && isset($userdata['ecs_uid_hash'])) {
                    $param = 'ecs_uid_hash'; // Use the deprecated 'ecs_uid_hash', if 'ecs_uid' not found.
                } else {
                    throw new coding_exception("generate_realm - required field '{$param}' missing from the userdata");
                }
            }
            $str .= $userdata[$param];
        }
        return sha1($str);
    }

    /**
     * Generate a hash of the full URL, which can be used to authenticate the data after calling get_auth
     *
     * @param string $url
     * @return string
     */
    public static function generate_realm($url) {
        $url = preg_replace('|&ecs_hash=[^&]*|', '', $url); // Remove any existing 'ecs_hash' param, before calculating.
        return sha1($url);
    }

    /**
     * Get a list of all the event queues (not supported by ECS server?)
     * @return object list of queues
     */
    public function get_event_queues() {
        $this->init_connection('/eventqueues');

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('get_event_queues - bad response: '.$this->get_status());
        }

        return $this->parse_json($result);
    }

    /**
     * Get the next event for this participant
     * @param bool $delete optional - true to delete the message once read
     * @return mixed object[] | bool the details of the event (false if no events left)
     */
    public function read_event_fifo($delete = false) {
        $this->init_connection('/sys/events/fifo');
        if ($delete) {
            $this->set_postfields('');
        }

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('read_event_fifo - bad response: '.$this->get_status());
        }

        return $this->parse_json($result);
    }

    /**
     * Get a list of available resources on the remote VLEs
     * @param string $type the type of resource to load (see \local_campusconnect\event for list)
     * @param string $sent optional set to \local_campusconnect\connect::SENT to get a list of resources sent out from this
     *                              site (instead of imported).
     * @param string $transferdetails optional set to \local_campusconnect\connect::TRANSFERDETAILS to get the transfer details,
     *                                         instead of the content
     * @throws coding_exception
     * @throws connect_exception
     * @return object|uri_list transfer details OR links to get further details about each resource
     */
    public function get_resource_list($type, $sent = self::RECEIVED,
                                      $transferdetails = self::CONTENT) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("get_resource_list: unknown resource type $type");
        }
        if (!in_array($sent, self::$validsent)) {
            throw new coding_exception("get_resource_list: invalid value for sent: $sent");
        }
        if (!in_array($transferdetails, self::$validtransferdetails)) {
            throw new coding_exception("get_resource_list: invalid value for transferdetails: $transferdetails");
        }
        $resourcepath = '/'.$type;
        if ($transferdetails == self::TRANSFERDETAILS) {
            $resourcepath .= '/details';
        }
        if ($sent == self::SENT) {
            $resourcepath .= '?sender=true';
        }
        $this->init_connection($resourcepath);

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('get_resource_list - bad response: '.$this->get_status());
        }

        if ($transferdetails == self::TRANSFERDETAILS) {
            $this->check_contenttype('application/json');
            return $this->parse_json($result);
        }

        $this->check_contenttype('text/uri-list');
        return $this->parse_uri_list($result);
    }

    /**
     * Get an individual resource
     * @param int $id of the resource to retrieve
     * @param string $type the type of resource to load (see \local_campusconnect\event for list)
     * @param string $transferdetails optional - if set to \local_campusconnect\connect::TRANSFERDETAILS, then retrieves the
     *                           delivery details for the resource
     * @return mixed object | details | false - the details retrieved
     */
    public function get_resource($id, $type, $transferdetails = self::CONTENT) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("get_resource: unknown resource type $type");
        }
        if (!in_array($transferdetails, self::$validtransferdetails)) {
            throw new coding_exception("get_resource_list: invalid value for transferdetails: $transferdetails");
        }
        $resourcepath = '/'.$type;
        if ($id) {
            $resourcepath .= "/$id";
        }
        if ($transferdetails == self::TRANSFERDETAILS) {
            $resourcepath .= '/details';
        }

        $this->init_connection($resourcepath);

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            //throw new connect_exception('get_resource - bad response: '.$this->get_status()." ($resourcepath)");
            return false; // Resource does not exist on the server.
        }

        if (!$this->check_contenttype('application/json', false)) {
            $this->check_contenttype('text/uri-list');
            return $this->get_from_uri_list($result);
        }

        if ($transferdetails == self::TRANSFERDETAILS) {
            return new details($this->parse_json($result));
        } else {
            return $this->parse_json($result);
        }
    }

    /**
     * Add a resource that other VLEs can retrieve
     * @param string $type the type of resource to load (see \local_campusconnect\event for list)
     * @param object|array|string $post the details of the resource to create
     * @param string $targetcommunityids a comma-separated list of community IDs that have access to this resource
     * @param string $targetmids a comma-separated list of participant IDs that have access to this resource
     * @return int the id that this resource has been allocated on the ECS
     */
    public function add_resource($type, $post, $targetcommunityids = null, $targetmids = null) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("add_resource: unknown resource type $type");
        }
        if (is_object($post) || is_array($post)) {
            $sendurilist = false;
        } else if (is_string($post)) {
            $sendurilist = true;
        } else {
            throw new coding_exception('add_resource - expected \'post\' to be an object or a string');
        }
        if (is_null($targetmids) && is_null($targetcommunityids)) {
            throw new coding_exception('add_resource - must specify either \'targetmids\' or \'targetcommunityids\'');
        } else if (!is_null($targetmids) && !is_null($targetcommunityids)) {
            throw new coding_exception('add_resource - cannot specify both \'targetmids\' and \'targetcommunityids\'');
        }

        if ($sendurilist) {
            $poststr = $post;
        } else {
            $poststr = json_encode($post);
        }
        $this->init_connection('/'.$type, $sendurilist);
        $this->set_postfields($poststr);

        self::log("add_resource $type - $targetcommunityids; $targetmids");
        self::log($poststr);

        $this->include_response_header();
        if (!is_null($targetmids)) {
            $this->set_memberships($targetmids);
        } else {
            $this->set_communities($targetcommunityids);
        }

        $this->call();
        if (!$this->check_status(self::HTTP_CODE_CREATED)) {
            throw new connect_exception('add_resource - bad response: '.$this->get_status());
        }

        return $this->get_econtentid_from_header();
    }

    /**
     * Update a previously shared resource
     * @param int $id the id allocated when the resource was first posted
     * @param string $type the type of resource to load (see \local_campusconnect\event for list)
     * @param object|string $post the new details
     * @param string $targetcommunityids a comma-separated list of community IDs that have access to this resource
     * @param string $targetmids a comma-separated list of participant IDs that have access to this resource
     * @return object the response from the ECS server
     */
    public function update_resource($id, $type, $post, $targetcommunityids = null, $targetmids = null) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("update_resource: unknown resource type $type");
        }
        if (is_object($post) || is_array($post)) {
            $sendurilist = false;
        } else if (is_string($post)) {
            $sendurilist = true;
        } else {
            throw new coding_exception('update_resource - expected \'post\' to be an object or a string');
        }
        if (!$id) {
            throw new connect_exception('update_resource - no resource id given');
        }
        if (is_null($targetmids) && is_null($targetcommunityids)) {
            throw new coding_exception('update_resource - must specify either \'targetmids\' or \'targetcommunityids\'');
        } else if (!is_null($targetmids) && !is_null($targetcommunityids)) {
            throw new coding_exception('update_resource - cannot specify both \'targetmids\' and \'targetcommunityids\'');
        }

        $this->init_connection("/$type/$id", $sendurilist);
        if (!is_null($targetmids)) {
            $this->set_memberships($targetmids);
        } else {
            $this->set_communities($targetcommunityids);
        }

        // Create a temporary file in memory.
        if (!$fp = fopen('php://temp', 'w+')) {
            throw new connect_exception('update_resource - unable to create temporary file');
        }
        if ($sendurilist) {
            $poststr = $post;
        } else {
            $poststr = json_encode($post);
        }

        self::log("update_resource $id $type - $targetcommunityids; $targetmids");
        self::log($poststr);

        fwrite($fp, $poststr);
        fseek($fp, 0);
        $this->set_putfile($fp, strlen($poststr));
        $result = $this->call();
        fclose($fp);

        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('update_resource - bad response: '.$this->get_status());
        }

        return $this->parse_json($result);
    }

    /**
     * Delete a previously shared resource
     * @param int $id the id allocated when the resource was first posted
     * @param string $type the type of resource to load (see \local_campusconnect\event for list)
     * @return object the response from the server
     */
    public function delete_resource($id, $type) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("delete_resource: unknown resource type $type");
        }
        if (!$id) {
            throw new connect_exception('delete_resource - no resource id given');
        }
        $this->init_connection("/$type/$id");
        $this->set_delete();

        $result = $this->call();
        $this->check_contenttype('application/json');
        return $this->parse_json($result);
    }

    /**
     * Get the details of the communities this VLE is a member of
     * @param int $mid optional the id of a specific community to retrieve?
     * @return object[] the details returned by the ECS server
     */
    public function get_memberships($mid = 0) {
        $resourcepath = '/sys/memberships';
        if ($mid) {
            $resourcepath .= "/$mid";
        }
        $this->init_connection($resourcepath);

        $result = $this->call();
        if (!$this->check_status(self::HTTP_CODE_OK)) {
            throw new connect_exception('get_memberships - bad response: '.$this->get_status());
        }
        $this->check_contenttype('application/json');
        return $this->parse_json($result);
    }

    /**
     * Generate the correct URL for a given resource. Note this does not check that the resource exists, it
     * combines the parameters with the base URL to generate the URL.
     * @param int $id the resourceid of the resource
     * @param string $type
     * @return string
     */
    public function get_resource_url($id, $type) {
        if (!event::is_valid_resource($type)) {
            throw new coding_exception("get_resource: unknown resource type $type");
        }
        return "{$type}/{$id}";
    }

    /**
     * Internal functions to check / parse the results
     */

    /**
     * Interpret the ECS server response as JSON
     * @param string $result the response from the ECS server
     * @return object the interpreted response
     */
    protected function parse_json($result) {
        return json_decode($result);
    }

    /**
     * Interpret the ECS server response as a list of URIs
     * @param string $result the response from the ECS server
     * @return uri_list list of URIs
     */
    protected function parse_uri_list($result) {
        $uris = new uri_list();
        $lines = explode("\n", $result);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            $id = explode("/", $line);
            $id = array_pop($id);
            $uris->add($line, $id);
        }
        return $uris;
    }

    /**
     * Check to see if the HTTP status of the request matched the given value
     * @param int $checkstatus the status to check against
     * @return bool - true if the status matched
     */
    protected function check_status($checkstatus) {
        return ($checkstatus == $this->get_status());
    }

    /**
     * Get the HTTP status of the request
     * @return int HTTP status code
     */
    protected function get_status() {
        return curl_getinfo($this->curlresource, CURLINFO_HTTP_CODE);
    }

    /**
     * Retrieve the assigned 'econtentid' from the response header
     * @return int the id
     */
    protected function get_econtentid_from_header() {
        $header = $this->responseheaders;
        if (!isset($header['Location'])) {
            return false;
        }
        $location = explode('/', $header['Location']);
        $id = array_pop($location);
        return intval($id);
    }

    /**
     * Extract the content type from the header
     * @return string the content type
     */
    protected function get_contenttype_from_header() {
        if (!isset($this->responseheaders['Content-Type'])) {
            return '';
        }
        $contenttype = explode(';', $this->responseheaders['Content-Type']);
        return trim($contenttype[0]);
    }

    /**
     * Check to see if the content type header matches the expected value.
     * @param string $expected the content type to check for
     * @param bool $throwexception optional if true (default), then throw an exception on failure, otherwise return false on failure
     * @return bool true if it matches
     */
    protected function check_contenttype($expected, $throwexception = true) {
        if ($this->get_contenttype_from_header() != $expected) {
            if ($throwexception) {
                throw new connect_exception("expected content type '$expected' got type '".
                                            $this->get_contenttype_from_header()."'");
            }
            return false;
        }
        return true;
    }

    /**
     * Callback function to parse the response header into an array of
     * headername => headervalue
     * @param resource $handle the curl resource
     * @param string $header the raw text of the HTTP header
     * @return int header length
     */
    protected function parse_response_header($handle, $header) {
        $lines = explode("\r\n", $header);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            list($name, $value) = $parts;
            $this->responseheaders[$name] = $value;
        }
        return strlen($header);
    }

    /**
     * Internal methods to simplify the use of curl
     */

    /**
     * Initialise the curl connection to a particular resource on the ECS server
     * @param string $resourcepath - the path to the desired resource on the server
     * @param bool $sendurilist
     */
    protected function init_connection($resourcepath, $sendurilist = false) {
        global $CFG;

        if (substr($resourcepath, 0, 1) != '/' || substr($resourcepath, -1) == '/') {
            throw new coding_exception('Resource path must start with \'/\' and not end with \'/\'');
        }
        $this->headers = array(); // Clear out any headers from previous calls.
        $this->curlresource = curl_init($this->settings->get_url().$resourcepath);

        // Set up standard options.
        $this->set_option(CURLOPT_RETURNTRANSFER, 1);
        $this->set_option(CURLOPT_VERBOSE, 1);
        $this->set_option(CURLINFO_HEADER_OUT, 1);
        $this->set_header('Accept', 'application/json');
        if ($sendurilist) {
            $this->set_header('Content-Type', 'text/uri-list');
        } else {
            $this->set_header('Content-Type', 'application/json');
        }

        // Set up proxy options.
        if (!empty($CFG->proxyhost) && !is_proxybypass($this->settings->get_url())) {
            $this->set_option(CURLOPT_PROXY, $CFG->proxyhost);
            if (!empty($CFG->proxyport)) {
                $this->set_option(CURLOPT_PROXYPORT, $CFG->proxyport);
            }
            if (!empty($CFG->proxytype)) {
                // Only set CURLOPT_PROXYTYPE if it's something other than the curl-default http.
                if ($CFG->proxytype == 'SOCKS5') {
                    $this->set_option(CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
            }
            if (!empty($CFG->proxyuser) and !empty($CFG->proxypassword)) {
                $this->set_option(CURLOPT_PROXYUSERPWD, $CFG->proxyuser.':'.$CFG->proxypassword);
                if (defined('CURLOPT_PROXYAUTH')) {
                    // Any proxy authentication if PHP 5.1.
                    $this->set_option(CURLOPT_PROXYAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
                }
            }
        }

        // Set up authentication options.
        switch ($this->settings->get_auth_type()) {
            case ecssettings::AUTH_NONE:
                $this->set_header('X-EcsAuthId', $this->settings->get_ecs_auth());
                break;

            case ecssettings::AUTH_HTTP:
                $this->set_option(CURLOPT_SSL_VERIFYHOST, 0);
                $this->set_option(CURLOPT_SSL_VERIFYPEER, 0);
                $this->set_option(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                $this->set_option(CURLOPT_USERPWD, $this->settings->get_http_user().':'.
                                                 $this->settings->get_http_password());
                break;

            case ecssettings::AUTH_CERTIFICATE:
                $this->set_option(CURLOPT_SSL_VERIFYHOST, 2);
                $this->set_option(CURLOPT_SSL_VERIFYPEER, 1);
                $this->set_option(CURLOPT_CAINFO, $this->settings->get_ca_cert_path());
                $this->set_option(CURLOPT_SSLCERT, $this->settings->get_client_cert_path());
                $this->set_option(CURLOPT_SSLKEY, $this->settings->get_key_path());
                $this->set_option(CURLOPT_SSLKEYPASSWD, $this->settings->get_key_pass());
                break;

            default:
                throw new coding_exception('Unknown auth type: '.$this->settings->get_auth_type());
                break;
        }

        self::log($this->settings->get_url().$resourcepath);
    }

    /**
     * Set the community participant(s) that this message is intended for
     * @param string $memberships comma-separated list of participant mids
     */
    protected function set_memberships($memberships) {
        $this->set_header('X-EcsReceiverMemberships', $memberships);
    }

    /**
     * Set the community(ies) that this message is intended for
     * @param string $communities comma-separated list of community ids
     */
    protected function set_communities($communities) {
        $this->set_header('X-EcsReceiverCommunities', $communities);
    }

    /**
     * Adds the post data to the request and sets the method to POST
     * @param mixed $post the parameters, either as urlencoded string or as
     *                    an array $key => $value
     */
    protected function set_postfields($post) {
        $this->set_option(CURLOPT_POST, true);
        $this->set_option(CURLOPT_POSTFIELDS, $post);
    }

    /**
     * Adds a file to the request and sets the method to PUT
     * @param resource $fp the file resource to read from
     * @param int $size the size of the file
     */
    protected function set_putfile($fp, $size) {
        $this->set_option(CURLOPT_PUT, true);
        $this->set_option(CURLOPT_UPLOAD, true);
        $this->set_option(CURLOPT_INFILE, $fp);
        $this->set_option(CURLOPT_INFILESIZE, $size);
    }

    /**
     * Set the request method to DELETE
     */
    protected function set_delete() {
        $this->set_option(CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    /**
     * Include the response header in the returned data
     */
    protected function include_response_header() {
        $this->set_option(CURLOPT_HEADER, true);
    }

    /**
     * Send the request to the server
     * @return string the result of the request
     */
    protected function call() {
        if (!$this->settings->is_enabled()) {
            throw new coding_exception('\local_campusconnect\connect: call() - should not be attempting to connect to'.
                                       ' disabled ECS ('.$this->get_ecs_id().')');
        }

        if ($this->debug) {
            $this->set_option(CURLINFO_HEADER_OUT, true);
        }

        $this->set_option(CURLOPT_HTTPHEADER, $this->get_headers());
        $this->set_option(CURLOPT_HEADERFUNCTION, array($this, 'parse_response_header'));
        $this->responseheaders = array();

        if (($res = curl_exec($this->curlresource)) === false) {
            throw new connect_exception('curl error: '.curl_error($this->curlresource).
                                        ' ('.curl_errno($this->curlresource).')');
        }

        if ($this->debug) {
            var_dump(curl_getinfo($this->curlresource));
        }

        self::log('Response: '.$res);

        return $res;
    }

    /**
     * Add a header to the list of HTTP headers to be added when the curl call is made
     * @param string $name the name of the header
     * @param string $value the value of the header
     */
    protected function set_header($name, $value) {
        $this->headers[$name] = $value;
    }

    /**
     * Add a curl option to be used when the curl call is made
     * @param int $option the option to set
     * @param mixed $value the value of the option
     */
    protected function set_option($option, $value) {
        curl_setopt($this->curlresource, $option, $value);
    }

    /**
     * Generate an array of all requested HTTP headers (ready to add to the call)
     * @return array of headers
     */
    protected function get_headers() {
        if (empty($this->headers)) {
            return false;
        }

        $ret = array();
        foreach ($this->headers as $key => $val) {
            $ret[] = "$key: $val";
        }
        return $ret;
    }

    /**
     * Given a list of URIs, download each of the resources from the URIs, JSON decode them and return as an
     * array
     * @param $urilist
     * @return \stdClass|null
     */
    protected function get_from_uri_list($urilist) {
        $ret = array();
        $urls = explode("\n", $urilist);
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue; // Ignore empty / whitespace only lines.
            }
            if ($url{0} == '#') {
                continue; // Ignore comment lines.
            }

            $c = curl_init($url);
            curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($c, CURLOPT_VERBOSE, 1);
            $res = curl_exec($c);
            if ($res === false) {
                throw new connect_exception('curl error: '.curl_error($c).
                                            ' ('.curl_errno($c).')');
            }
            $result = json_decode($res);
            if (is_null($result)) {
                $details = "\nURL: $url \nReturned data: $res";
                throw new connect_exception('Invalid item downloaded from resource'.$details);
            }

            return $result;
            /*
            // Retained in case we want to return more than one item in the furture.
            if (is_array($result)) {
                $ret = array_merge($ret, $result);
            } else {
                $ret[] = $result;
            }
            */
        }
        //return $ret;
        return null;
    }

    /**
     * If $CFG->campusconnect_log_connection is defined, output the msg to the log file.
     * @param $msg
     */
    protected function log($msg) {
        global $CFG;
        if (empty($CFG->campusconnect_log_connection)) {
            return;
        }
        log::add($msg, false, false, false);
    }
}