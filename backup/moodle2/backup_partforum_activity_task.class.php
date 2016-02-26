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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/mod/partforum/backup/moodle2/backup_partforum_stepslib.php'); // Because it exists (must)
require_once($CFG->dirroot . '/mod/partforum/backup/moodle2/backup_partforum_settingslib.php'); // Because it exists (optional)

/**
 * forum backup task that provides all the settings and steps to perform one
 * complete backup of the activity
 */
class backup_partforum_activity_task extends backup_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Forum only has one structure step
        $this->add_step(new backup_forum_activity_structure_step('partforum structure', 'partforum.xml'));
    }

    /**
     * Code the transformations to perform in the activity in
     * order to get transportable (encoded) links
     */
    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot,"/");

        // Link to the list of forums
        $search="/(".$base."\/mod\/partforum\/index.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMINDEX*$2@$', $content);

        // Link to forum view by moduleid
        $search="/(".$base."\/mod\/partforum\/view.php\?id\=)([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMVIEWBYID*$2@$', $content);

        // Link to forum view by forumid
        $search="/(".$base."\/mod\/partforum\/view.php\?f\=)([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMVIEWBYF*$2@$', $content);

        // Link to forum discussion with parent syntax
        $search="/(".$base."\/mod\/partforum\/discuss.php\?d\=)([0-9]+)\&parent\=([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMDISCUSSIONVIEWPARENT*$2*$3@$', $content);

        // Link to forum discussion with relative syntax
        $search="/(".$base."\/mod\/partforum\/discuss.php\?d\=)([0-9]+)\#([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMDISCUSSIONVIEWINSIDE*$2*$3@$', $content);

        // Link to forum discussion by discussionid
        $search="/(".$base."\/mod\/partforum\/discuss.php\?d\=)([0-9]+)/";
        $content= preg_replace($search, '$@PARTFORUMDISCUSSIONVIEW*$2@$', $content);

        return $content;
    }
}
