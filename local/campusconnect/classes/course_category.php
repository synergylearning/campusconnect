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
 * Handle 'course' creation requests from the ECS server
 *
 * @package   local_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

defined('MOODLE_INTERNAL') || die();

/**
 * Stores details about the Moodle category in which to create a course
 */
class course_category {
    /** @var int $categorid */
    protected $categoryid;
    /** @var int $order */
    protected $order;
    /** @var int $directory */
    protected $directoryid;

    /**
     * @param int $categoryid
     * @param int $order
     * @param int $directoryid
     */
    public function __construct($categoryid, $order = 0, $directoryid = 0) {
        $this->categoryid = $categoryid;
        $this->order = $order;
        $this->directoryid = $directoryid;
    }

    /**
     * The Moodle category in which to create the course
     * @return int
     */
    public function get_categoryid() {
        return $this->categoryid;
    }

    /**
     * The sort order within the course
     * @return int
     */
    public function get_order() {
        return $this->order;
    }

    /**
     * The Moodle directory in which to create the course
     * @return int
     */
    public function get_directoryid() {
        return $this->directoryid;
    }
}
