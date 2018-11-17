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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/partforum/backup/moodle2/restore_partforum_stepslib.php'); // Because it exists (must)

/**
 * partforum restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_partforum_activity_task extends restore_activity_task {

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
        // Choice only has one structure step
        $this->add_step(new restore_partforum_activity_structure_step('partforum_structure', 'partforum.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('partforum', array('intro'), 'partforum');
        $contents[] = new restore_decode_content('partforum_posts', array('message'), 'partforum_post');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        // List of partforums in course
        $rules[] = new restore_decode_rule('PARTFORUMINDEX', '/mod/partforum/index.php?id=$1', 'course');
        // Forum by cm->id and partforum->id
        $rules[] = new restore_decode_rule('PARTFORUMVIEWBYID', '/mod/partforum/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('PARTFORUMVIEWBYF', '/mod/partforum/view.php?f=$1', 'partforum');
        // Link to partforum discussion
        $rules[] = new restore_decode_rule('PARTFORUMDISCUSSIONVIEW', '/mod/partforum/discuss.php?d=$1', 'partforum_discussion');
        // Link to discussion with parent and with anchor posts
        $rules[] = new restore_decode_rule('PARTFORUMDISCUSSIONVIEWPARENT', '/mod/partforum/discuss.php?d=$1&parent=$2',
                                           array('partforum_discussion', 'partforum_post'));
        $rules[] = new restore_decode_rule('PARTFORUMDISCUSSIONVIEWINSIDE', '/mod/partforum/discuss.php?d=$1#$2',
                                           array('partforum_discussion', 'partforum_post'));

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * partforum logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('partforum', 'add', 'view.php?id={course_module}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'update', 'view.php?id={course_module}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'view', 'view.php?id={course_module}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'view partforum', 'view.php?id={course_module}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'mark read', 'view.php?f={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'start tracking', 'view.php?f={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'stop tracking', 'view.php?f={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'subscribe', 'view.php?f={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'unsubscribe', 'view.php?f={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'subscriber', 'subscribers.php?id={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'subscribers', 'subscribers.php?id={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'view subscribers', 'subscribers.php?id={partforum}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'add discussion', 'discuss.php?d={partforum_discussion}', '{partforum_discussion}');
        $rules[] = new restore_log_rule('partforum', 'view discussion', 'discuss.php?d={partforum_discussion}', '{partforum_discussion}');
        $rules[] = new restore_log_rule('partforum', 'move discussion', 'discuss.php?d={partforum_discussion}', '{partforum_discussion}');
        $rules[] = new restore_log_rule('partforum', 'delete discussi', 'view.php?id={course_module}', '{partforum}',
                                        null, 'delete discussion');
        $rules[] = new restore_log_rule('partforum', 'delete discussion', 'view.php?id={course_module}', '{partforum}');
        $rules[] = new restore_log_rule('partforum', 'add post', 'discuss.php?d={partforum_discussion}&parent={partforum_post}', '{partforum_post}');
        $rules[] = new restore_log_rule('partforum', 'update post', 'discuss.php?d={partforum_discussion}&parent={partforum_post}', '{partforum_post}');
        $rules[] = new restore_log_rule('partforum', 'prune post', 'discuss.php?d={partforum_discussion}', '{partforum_post}');
        $rules[] = new restore_log_rule('partforum', 'delete post', 'discuss.php?d={partforum_discussion}', '[post]');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('partforum', 'view partforums', 'index.php?id={course}', null);
        $rules[] = new restore_log_rule('partforum', 'subscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('partforum', 'unsubscribeall', 'index.php?id={course}', '{course}');
        $rules[] = new restore_log_rule('partforum', 'user report', 'user.php?course={course}&id={user}&mode=[mode]', '{user}');
        $rules[] = new restore_log_rule('partforum', 'search', 'search.php?id={course}&search=[searchenc]', '[search]');

        return $rules;
    }
}
