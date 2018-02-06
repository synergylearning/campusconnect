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
 * Main entry point for CampusConnect block
 *
 * @package    block_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_campusconnect\export;

defined('MOODLE_INTERNAL') || die();

class block_campusconnect extends block_base {
    function init() {
        $this->title = get_string('pluginname', 'block_campusconnect');
    }

    function applicable_formats() {
        return array('course' => true, 'course-category' => false, 'site' => true);
    }

    function instance_allow_multiple() {
        return false;
    }

    function get_content() {
        global $PAGE, $COURSE, $SITE;

        if ($this->content !== null) {
            return $this->content;
        }

        if ($COURSE->id == $SITE->id) {
            return null; // Cannot export the SITE course.
        }

        if (!$PAGE->user_is_editing()) {
            return null; // Only visible when editing is on.
        }

        if (!has_capability('moodle/course:update', $this->context)) {
            return null; // Only visible to users with course:update capability.
        }

        $export = new export($COURSE->id);

        $this->content = new stdClass();
        $editurl = new moodle_url('/blocks/campusconnect/export.php', array('courseid' => $COURSE->id));
        $editlink = html_writer::link($editurl, get_string('editexport', 'block_campusconnect'));
        $this->content->footer = $editlink;
        if (!$export->is_exported()) {
            $this->content->text = get_string('notexported', 'block_campusconnect');
        } else {
            $this->content->text = get_string('exportedto', 'block_campusconnect').':';
            $list = $export->list_current_exports();
            $outlist = '';
            foreach ($list as $part) {
                $outlist .= html_writer::tag('li', s($part->get_displayname()));
            }
            $this->content->text .= html_writer::tag('ul', $outlist);
        }

        return $this->content;
    }
}