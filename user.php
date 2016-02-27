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
 * Display user activity reports for a course
 *
 * @package mod-forum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');

// Course ID
$course  = optional_param('course', SITEID, PARAM_INT);
// User ID
$id      = optional_param('id', 0, PARAM_INT);
$mode    = optional_param('mode', 'posts', PARAM_ALPHA);
$page    = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 5, PARAM_INT);

$url = new moodle_url('/mod/partforum/user.php');
if ($course !== SITEID) {
    $url->param('course', $course);
}
if ($id !== 0) {
    $url->param('id', $id);
}
if ($mode !== 'posts') {
    $url->param('mode', $mode);
}
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');

if (empty($id)) {         // See your own profile by default
    require_login();
    $id = $USER->id;
}

$user = $DB->get_record("user", array("id" => $id), '*', MUST_EXIST);
$course = $DB->get_record("course", array("id" => $course), '*', MUST_EXIST);

$syscontext = get_context_instance(CONTEXT_SYSTEM);
$usercontext   = get_context_instance(CONTEXT_USER, $id);

// do not force parents to enrol
if (!$DB->get_record('role_assignments', array('userid' => $USER->id, 'contextid' => $usercontext->id))) {
    require_course_login($course);
} else {
    $PAGE->set_course($course);
}

if ($user->deleted) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('userdeleted'));
    echo $OUTPUT->footer();
    die;
}

add_to_log($course->id, "partforum", "user report",
        "user.php?course=$course->id&amp;id=$user->id&amp;mode=$mode", "$user->id");

$strforumposts   = get_string('forumposts', 'partforum');
$strparticipants = get_string('participants');
$strmode         = get_string($mode, 'partforum');
$fullname        = fullname($user, has_capability('moodle/site:viewfullnames', $syscontext));

$link = null;
if (has_capability('moodle/course:viewparticipants', get_context_instance(CONTEXT_COURSE, $course->id)) || has_capability('moodle/site:viewparticipants', $syscontext)) {
    $link = new moodle_url('/user/index.php',array('id'=>$course->id));
}

$PAGE->navigation->extend_for_user($user);
$PAGE->navigation->set_userid_for_parent_checks($id); // see MDL-25805 for reasons and for full commit reference for reversal when fixed.
$PAGE->set_title("$course->shortname: $fullname: $strmode");
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($fullname);

switch ($mode) {
    case 'posts' :
        $searchterms = array('userid:'.$user->id);
        $extrasql = '';
        break;

    default:
        $searchterms = array('userid:'.$user->id);
        $extrasql = 'AND p.parent = 0';
        break;
}

echo '<div class="user-content">';

if ($course->id == SITEID) {
    $searchcourse = SITEID;
    if (empty($CFG->forceloginforprofiles) or (isloggedin() and !isguestuser() and !is_web_crawler())) {
        // Search throughout the whole site.
        $searchcourse = 0;
    }
} else {
    // Search only for posts the user made in this course.
    $searchcourse = $course->id;
}

// Get the posts.
$posts = partforum_search_posts($searchterms, $searchcourse, $page*$perpage, $perpage, $totalcount, $extrasql);
if ($posts) {

    require_once($CFG->dirroot.'/rating/lib.php');

    $baseurl = new moodle_url('user.php', array('id' => $user->id, 'course' => $course->id, 'mode' => $mode, 'perpage' => $perpage));
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);

    $discussions = array();
    $forums      = array();

    //todo Rather than retrieving the ratings for each post individually it would be nice to do them in groups
    //however this requires creating arrays of posts with each array containing all of the posts from a particular forum,
    //retrieving the ratings then reassembling them all back into a single array sorted by post.modified (descending)
    $rm = new rating_manager();
    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_partforum';
    $ratingoptions->ratingarea = 'post';

    foreach ($posts as $post) {

        if (!isset($discussions[$post->discussion])) {
            if (! $discussion = $DB->get_record('partforum_discussions', array('id' => $post->discussion))) {
                print_error('invaliddiscussionid', 'partforum');
            }
            $discussions[$post->discussion] = $discussion;
        } else {
            $discussion = $discussions[$post->discussion];
        }

        if (!isset($forums[$discussion->forum])) {
            $forum = $DB->get_record('partforum', array('id' => $discussion->forum), '*', MUST_EXIST);
            $forum->cm = get_coursemodule_from_instance('partforum', $forum->id, 0, false, MUST_EXIST);
            $forum->context = get_context_instance(CONTEXT_MODULE, $forum->cm->id);
            $forums[$discussion->forum] = $forum;
        } else {
            $forum = $forums[$discussion->forum];
        }

        $forumurl = new moodle_url('/mod/partforum/view.php', array('id' => $forum->cm->id));
        $discussionurl = new moodle_url('/mod/partforum/discuss.php', array('d' => $discussion->id));

        // load ratings
        if ($forum->assessed != RATING_AGGREGATE_NONE) {
            $ratingoptions->context = $forum->context;
            $ratingoptions->items = array($post);
            $ratingoptions->aggregate = $forum->assessed;//the aggregation method
            $ratingoptions->scaleid = $forum->scale;
            $ratingoptions->userid = $user->id;
            $ratingoptions->assesstimestart = $forum->assesstimestart;
            $ratingoptions->assesstimefinish = $forum->assesstimefinish;
            if ($forum->type == 'single' or !$discussion->id) {
                $ratingoptions->returnurl = $forumurl;
            } else {
                $ratingoptions->returnurl = $discussionurl;
            }

            $updatedpost = $rm->get_ratings($ratingoptions);
            //updating the array this way because we're iterating over a collection and updating them one by one
            $posts[$updatedpost[0]->id] = $updatedpost[0];
        }

        $fullsubjects = array();
        if ($course->id == SITEID && has_capability('moodle/site:config', $syscontext)) {
            $postcoursename = $DB->get_field('course', 'shortname', array('id'=>$forum->course));
            $courseurl = new moodle_url('/course/view.php', array('id' => $forum->course));
            $fullsubjects[] = html_writer::link($courseurl, $postcoursename);
        }
        $fullsubjects[] = html_writer::link($forumurl, format_string($forum->name, true));
        if ($forum->type != 'single') {
            $fullsubjects[] .= html_writer::link($discussionurl, format_string($discussion->name, true));
            if ($post->parent != 0) {
                $parenturl = new moodle_url('/mod/partforum/discuss.php', array('d' => $post->discussion, 'parent' => $post->id));
                $fullsubjects[] .= html_writer::link($parenturl, format_string($post->subject, true));
            }
        }

        $post->subject = join(' -> ', $fullsubjects);
        $discussionurl->set_anchor('p'.$post->id);
        $fulllink = html_writer::link($discussionurl, get_string("postincontext", "partforum"));

        partforum_print_post($post, $discussion, $forum, $forum->cm, $course, false, false, false, $fulllink);
        echo "<br />";
    }

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $baseurl);
} else {
    if ($mode == 'posts') {
        echo $OUTPUT->heading(get_string('noposts', 'partforum'));
    } else {
        echo $OUTPUT->heading(get_string('nodiscussionsstartedby', 'partforum'));
    }
}
echo '</div>';
echo $OUTPUT->footer();

