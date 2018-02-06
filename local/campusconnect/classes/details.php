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
 * Container for the details linked to a received resource
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

defined('MOODLE_INTERNAL') || die();

class details {
    protected $url;
    protected $receivers;
    protected $senders;
    protected $owner;
    protected $contenttype;

    public function __construct($details) {
        $this->url = $details->url;
        $this->receivers = $details->receivers;
        $this->senders = $details->senders;
        $this->owner = $details->owner;
        $this->contenttype = $details->content_type;
    }

    public function is_mine() {
        return $this->owner->itsyou;
    }

    public function get_sender_mid() {
        foreach ($this->receivers as $pos => $receiver) {
            if ($receiver->itsyou) {
                return $this->senders[$pos]->mid;
            }
        }
        if ($this->is_mine()) {
            // Not on the list of receivers, but was the sender, so assume 'sender[0]' is a suitable answer.
            return $this->senders[0]->mid;
        }
        throw new connect_exception("This participant is not in the list of receivers");
    }

    public function sent_by_me(array $mymids) {
        return in_array($this->senders[0]->mid, $mymids);
    }

    public function received_by($mid) {
        foreach ($this->receivers as $receiver) {
            if ($receiver->mid == $mid) {
                return true;
            }
        }
        return false;
    }
}