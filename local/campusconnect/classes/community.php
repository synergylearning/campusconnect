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

defined('MOODLE_INTERNAL') || die();

class community {
    public $name;
    public $desciption;
    public $ecsid;
    /** @var participantsettings[] */
    public $participants = array();

    public function __construct($ecsid, $name, $description) {
        $this->name = $name;
        $this->description = $description;
        $this->ecsid = $ecsid;
    }

    public function add_participant(participantsettings $part) {
        $this->participants[$part->get_identifier()] = $part;
    }

    public function remove_participant(participantsettings $part) {
        unset($this->participants[$part->get_identifier()]);
    }

    public function has_participants() {
        return !!($this->participants);
    }
}
