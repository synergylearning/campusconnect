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
 * Connection exception
 *
 * @package   local_campusconnect
 * @copyright 2016 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

class connect_exception extends moodle_exception {
    public function __construct($msg) {
        parent::__construct('error', 'local_campusconnect', '', $msg);
        $this->email_admin($msg);
    }

    protected function email_admin($msg) {
        // TODO - implement this function
        // May need to consider gathering the errors into a log and only sending emails at most once an hour?
    }
}
