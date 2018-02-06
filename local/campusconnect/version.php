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
 * version file for CampusConnect
 *
 * @package    local_campusconnect
 * @copyright  2012 Synergy Learning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$plugin->version = 2016111700;
$plugin->requires = 2015051100; // Moodle 2.9+
$plugin->cron = 1; // Run every second (or as often as cron is run)
$plugin->component = 'local_campusconnect';
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '2.9+ (Build: 2016111700)';
$plugin->dependencies = array(
    'auth_campusconnect' => ANY_VERSION,
    'block_campusconnect' => ANY_VERSION,
    'enrol_campusconnect' => ANY_VERSION
);
