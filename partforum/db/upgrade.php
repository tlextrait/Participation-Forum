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
 * This file keeps track of upgrades to
 * the partforum module
 *
 * Sometimes, changes between versions involve
 * alterations to database structures and other
 * major things that may break installations.
 *
 * The upgrade function in this file will attempt
 * to perform all the necessary actions to upgrade
 * your older installation to the current version.
 *
 * If there's something it cannot do itself, it
 * will tell you what you need to do.
 *
 * The commands in here will all be database-neutral,
 * using the methods of database_manager class
 *
 * Please do not forget to use upgrade_set_timeout()
 * before any action that may take longer time to finish.
 *
 * @package mod-partforum
 * @copyright 2012 onwards The University of Hong Kong
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_partforum_upgrade($oldversion) {
	global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager(); // loads ddl manager and xmldb classes
        // Moodle v3.0.3 release upgrade line.
    if ($oldversion < 2016053100) {
        
        // Rename field question on table partforum_discussions to partforum.
        $table1 = new xmldb_table('partforum_discussions');
        $field1 = new xmldb_field('forum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', null);

        // Launch rename field question.
        if ($dbman->field_exists($table1, $field1)) {
            $dbman->rename_field($table1, $field1, 'partforum');
        }        
        //-----------------------------------------------------------------------------
        
         // Rename field question on table partforum_read to partforumid.
        $table2 = new xmldb_table('partforum_read');
        $field2 = new xmldb_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', null);

        // Launch rename field question.
        if ($dbman->field_exists($table2, $field2)) {
            $dbman->rename_field($table2, $field2, 'partforumid');
        }
        //-----------------------------------------------------------------------------
        
        // Rename field question on table partforum_subscriptions to partforum.
        $table3 = new xmldb_table('partforum_subscriptions');
        $field3 = new xmldb_field('forum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', null);

        // Launch rename field question.
        if ($dbman->field_exists($table3, $field3)) {
           $dbman->rename_field($table3, $field3, 'partforum');
        }
        //-----------------------------------------------------------------------------
        
        // Rename field question on table partforum_track_prefs to partforumid.
        $table4 = new xmldb_table('partforum_track_prefs');
        $field4 = new xmldb_field('forumid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', null);

        // Launch rename field question.
        if ($dbman->field_exists($table4, $field4)) {
            $dbman->rename_field($table4, $field4, 'partforumid');
        }
        //-----------------------------------------------------------------------------
        
        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2016053100, 'partforum');        
       
    }
    
    return true;
}


