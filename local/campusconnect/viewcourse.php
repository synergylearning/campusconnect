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
 * Redirect incoming course links to the correct course, after checking user login
 *
 * @package   local_campusconnect
 * @copyright 2013 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../config.php');
global $DB, $SESSION, $FULLME;

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

// Retain the entire URL just as it is - otherwise the hash will not be calculated properly.
$SESSION->wantsurl = $FULLME;

// Make sure the 'wantsurl' param is not overridden by require_login => this will redirect to the course + test
// authentication, if not already logged in.
require_login($course, false, null, false);

// If we get this far, then it means we were already logged in - just do a straight redirect.
$url = new moodle_url('/course/view.php', array('id' => $course->id));
redirect($url);