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
 * Database upgrade steps for auth_campusconnect
 *
 * @package   auth_campusconnect
 * @copyright 2012 Davo Smith, Synergy Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_auth_campusconnect_upgrade($oldversion) {
    global $DB, $CFG;

    $dbman = $DB->get_manager();

    if ($oldversion < 2012111500) {

        // Define table auth_campusconnect to be created.
        $table = new xmldb_table('auth_campusconnect');

        // Adding fields to table auth_campusconnect.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ecsid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ecs_uid', XMLDB_TYPE_CHAR, '60', null, null, null, null);
        $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table auth_campusconnect.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('ecsid', XMLDB_KEY_FOREIGN, array('ecsid'), 'local_campusconnect_ecs', array('id'));

        // Adding indexes to table auth_campusconnect.
        $table->add_index('ecs_uid', XMLDB_INDEX_UNIQUE, array('ecs_uid'));

        // Conditionally launch create table for auth_campusconnect.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2012111500, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014012200) {
        // Define field lastenroled to be added to auth_campusconnect.
        $table = new xmldb_table('auth_campusconnect');
        $field = new xmldb_field('lastenroled', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'username');

        // Conditionally launch add field lastenroled.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index username (unique) to be added to auth_campusconnect.
        $index = new xmldb_index('username', XMLDB_INDEX_UNIQUE, array('username'));

        // Conditionally launch add index username.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014012200, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014012300) {
        require_once($CFG->dirroot.'/auth/campusconnect/db/upgradelib.php');
        auth_campusconnect_populate_lastenroled();

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014012300, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014061900) {

        // Define field pid to be added to auth_campusconnect.
        $table = new xmldb_table('auth_campusconnect');
        $field = new xmldb_field('pid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'ecsid');

        // Conditionally launch add field pid.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014061900, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014062000) {

        // Define field personidtype to be added to auth_campusconnect.
        $table = new xmldb_table('auth_campusconnect');
        $field = new xmldb_field('personidtype', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'ecs_uid', 'lastenroled');

        // Conditionally launch add field personidtype.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014062000, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014062001) {

        // Remove the index on ecs_uid.
        $table = new xmldb_table('auth_campusconnect');
        $index = new xmldb_index('ecs_uid', XMLDB_INDEX_UNIQUE, array('ecs_uid'));

        // Conditionally launch drop index ecs_uid.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Rename field ecs_uid to personid.
        $field = new xmldb_field('ecs_uid', XMLDB_TYPE_CHAR, '60', null, null, null, null, 'pid');

        // Launch rename field ecs_uid.
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'personid');
        }

        // Create a new index on personid + personidtype.
        $index = new xmldb_index('personid', XMLDB_INDEX_UNIQUE, array('personid', 'personidtype'));

        // Conditionally launch add index ecs_uid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014062001, 'auth', 'campusconnect');
    }

    if ($oldversion < 2014062400) {

        // Define field suspended to be added to auth_campusconnect.
        $table = new xmldb_table('auth_campusconnect');
        $field = new xmldb_field('suspended', XMLDB_TYPE_INTEGER, '2', null, null, null, '0', 'personidtype');

        // Conditionally launch add field suspended.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014062400, 'auth', 'campusconnect');
    }

    // Transition from ecsid + pid => pids.

    if ($oldversion < 2014081900) {

        // Define field pids to be added to auth_campusconnect.
        $table = new xmldb_table('auth_campusconnect');
        $field = new xmldb_field('pids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'ecsid');

        // Conditionally launch add field pids.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014081900, 'auth', 'campusconnect');
    }
    if ($oldversion < 2014081901) {

        // Copy the old data from ecsid + pid into pids.
        mtrace("Converting user pid records - this may take a while to complete");
        $rs = $DB->get_recordset_select('auth_campusconnect', "pids IS NULL AND pid IS NOT NULL AND pid <> ''", null,
                                        '', 'id, ecsid, pid');
        foreach ($rs as $authcc) {
            $pids = "{$authcc->ecsid}_{$authcc->pid}";
            $DB->set_field('auth_campusconnect', 'pids', $pids, array('id' => $authcc->id));
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014081901, 'auth', 'campusconnect');
    }
    if ($oldversion < 2014081902) {

        // Remove the old fields.
        $table = new xmldb_table('auth_campusconnect');

        // Define key ecsid (foreign) to be dropped form auth_campusconnect.
        $key = new xmldb_key('ecsid', XMLDB_KEY_FOREIGN, array('ecsid'), 'local_campusconnect_ecs', array('id'));
        $dbman->drop_key($table, $key);

        // Define field ecsid to be dropped from auth_campusconnect.
        $field = new xmldb_field('ecsid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Define field pid to be dropped from auth_campusconnect.
        $field = new xmldb_field('pid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Campusconnect savepoint reached.
        upgrade_plugin_savepoint(true, 2014081902, 'auth', 'campusconnect');
    }

    return true;
}
