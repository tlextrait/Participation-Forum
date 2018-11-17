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
 * Definition of log events
 *
 * @package    mod
 * @subpackage partforum
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $DB; // TODO: this is a hack, we should really do something with the SQL in SQL tables

$logs = array(
    array('module'=>'partforum', 'action'=>'add', 'mtable'=>'partforum', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'update', 'mtable'=>'partforum', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'add discussion', 'mtable'=>'partforum_discussions', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'add post', 'mtable'=>'partforum_posts', 'field'=>'subject'),
    array('module'=>'partforum', 'action'=>'update post', 'mtable'=>'partforum_posts', 'field'=>'subject'),
    array('module'=>'partforum', 'action'=>'user report', 'mtable'=>'user', 'field'=>$DB->sql_concat('firstname', "' '" , 'lastname')),
    array('module'=>'partforum', 'action'=>'move discussion', 'mtable'=>'partforum_discussions', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'view subscribers', 'mtable'=>'partforum', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'view discussion', 'mtable'=>'partforum_discussions', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'view partforum', 'mtable'=>'partforum', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'subscribe', 'mtable'=>'partforum', 'field'=>'name'),
    array('module'=>'partforum', 'action'=>'unsubscribe', 'mtable'=>'partforum', 'field'=>'name'),
);