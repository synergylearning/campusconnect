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
 * Log and problems with incomming data from the ECS
 *
 * @package   local_campusconnect
 * @copyright 2013 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_campusconnect;

defined('MOODLE_INTERNAL') || die();

class log {
    const LOGNAME = 'campusconnect.log';

    public static function add($msg, $debugonly = false, $output = true, $serverlog = true) {
        global $CFG;

        if ($debugonly && $CFG->debug < DEBUG_DEVELOPER) {
            return;
        }

        $fp = fopen($CFG->dataroot.'/'.self::LOGNAME, 'a');

        if (!$fp) {
            return;
        }

        fwrite($fp, date('j M Y H:i:s').' - '.$msg."\n");
        fclose($fp);

        if ($output) {
            mtrace($msg);
        }
        if ($serverlog) {
            debugging($msg, DEBUG_NORMAL);
        }
    }

    public static function add_object($obj, $debugonly = false, $serverlog = true) {
        ob_start();
        print_r($obj);
        $out = ob_get_clean();
        self::add($out, $debugonly, false, $serverlog);
    }

    public static function outputlog() {
        global $CFG;
        $filename = $CFG->dataroot.'/'.self::LOGNAME;
        if (file_exists($filename)) {
            readfile($filename);
        } else {
            echo "No log entries";
        }
    }

    public static function clearlog() {
        global $CFG;
        @unlink($CFG->dataroot.'/'.self::LOGNAME);
    }
}