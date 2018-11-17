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
 * Subscribe to or unsubscribe from a partforum or manage partforum subscription mode
 *
 * This script can be used by either individual users to subscribe to or
 * unsubscribe from a partforum (no 'mode' param provided), or by partforum managers
 * to control the subscription mode (by 'mode' param).
 * This script can be called from a link in email so the sesskey is not
 * required parameter. However, if sesskey is missing, the user has to go
 * through a confirmation page that redirects the user back with the
 * sesskey.
 *
 * @package    mod
 * @subpackage partforum
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->dirroot.'/mod/partforum/lib.php');

$id      = required_param('id', PARAM_INT);             // the partforum to subscribe or unsubscribe to
$mode    = optional_param('mode', null, PARAM_INT);     // the partforum's subscription mode
$user    = optional_param('user', 0, PARAM_INT);        // userid of the user to subscribe, defaults to $USER
$sesskey = optional_param('sesskey', null, PARAM_RAW);  // sesskey

$url = new moodle_url('/mod/partforum/subscribe.php', array('id'=>$id));
if (!is_null($mode)) {
    $url->param('mode', $mode);
}
if ($user !== 0) {
    $url->param('user', $user);
}
if (!is_null($sesskey)) {
    $url->param('sesskey', $sesskey);
}
$PAGE->set_url($url);

$partforum   = $DB->get_record('partforum', array('id' => $id), '*', MUST_EXIST);
$course  = $DB->get_record('course', array('id' => $partforum->course), '*', MUST_EXIST);
$cm      = get_coursemodule_from_instance('partforum', $partforum->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);

if ($user) {
    require_sesskey();
    if (!has_capability('mod/partforum:managesubscriptions', $context)) {
        print_error('nopermissiontosubscribe', 'partforum');
    }
    $user = $DB->get_record('user', array('id' => $user), MUST_EXIST);
} else {
    $user = $USER;
}

if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
    $groupmode = $cm->groupmode;
} else {
    $groupmode = $course->groupmode;
}
if ($groupmode && !partforum_is_subscribed($user->id, $partforum) && !has_capability('moodle/site:accessallgroups', $context)) {
    if (!groups_get_all_groups($course->id, $USER->id)) {
        print_error('cannotsubscribe', 'partforum');
    }
}

require_login($course->id, false, $cm);

if (is_null($mode) and !is_enrolled($context, $USER, '', true)) {   // Guests and visitors can't subscribe - only enrolled
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    if (isguestuser()) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('subscribeenrolledonly', 'partforum').'<br /><br />'.get_string('liketologin'),
                     get_login_url(), new moodle_url('/mod/partforum/view.php', array('f'=>$id)));
        echo $OUTPUT->footer();
        exit;
    } else {
        // there should not be any links leading to this place, just redirect
        redirect(new moodle_url('/mod/partforum/view.php', array('f'=>$id)), get_string('subscribeenrolledonly', 'partforum'));
    }
}

$returnto = optional_param('backtoindex',0,PARAM_INT)
    ? "index.php?id=".$course->id
    : "view.php?f=$id";

if (!is_null($mode) and has_capability('mod/partforum:managesubscriptions', $context)) {
    require_sesskey();
    switch ($mode) {
        case PARTFORUM_CHOOSESUBSCRIBE : // 0
            partforum_forcesubscribe($partforum->id, 0);
            redirect($returnto, get_string("everyonecannowchoose", "partforum"), 1);
            break;
        case PARTFORUM_FORCESUBSCRIBE : // 1
            partforum_forcesubscribe($partforum->id, 1);
            redirect($returnto, get_string("everyoneisnowsubscribed", "partforum"), 1);
            break;
        case PARTFORUM_INITIALSUBSCRIBE : // 2
            partforum_forcesubscribe($partforum->id, 2);
            redirect($returnto, get_string("everyoneisnowsubscribed", "partforum"), 1);
            break;
        case PARTFORUM_DISALLOWSUBSCRIBE : // 3
            partforum_forcesubscribe($partforum->id, 3);
            redirect($returnto, get_string("noonecansubscribenow", "partforum"), 1);
            break;
        default:
            print_error(get_string('invalidforcesubscribe', 'partforum'));
    }
}

if (partforum_is_forcesubscribed($partforum)) {
    redirect($returnto, get_string("everyoneisnowsubscribed", "partforum"), 1);
}

$info->name  = fullname($user);
$info->partforum = format_string($partforum->name);

if (partforum_is_subscribed($user->id, $partforum->id)) {
    if (is_null($sesskey)) {    // we came here via link in email
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmunsubscribe', 'partforum', format_string($partforum->name)),
                new moodle_url($PAGE->url, array('sesskey' => sesskey())), new moodle_url('/mod/partforum/view.php', array('f' => $id)));
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    if (partforum_unsubscribe($user->id, $partforum->id)) {
        add_to_log($course->id, "partforum", "unsubscribe", "view.php?f=$partforum->id", $partforum->id, $cm->id);
        redirect($returnto, get_string("nownotsubscribed", "partforum", $info), 1);
    } else {
        print_error('cannotunsubscribe', 'partforum', $_SERVER["HTTP_REFERER"]);
    }

} else {  // subscribe
    if ($partforum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE &&
                !has_capability('mod/partforum:managesubscriptions', $context)) {
        print_error('disallowsubscribe', 'partforum', $_SERVER["HTTP_REFERER"]);
    }
    if (!has_capability('mod/partforum:viewdiscussion', $context)) {
        print_error('noviewdiscussionspermission', 'partforum', $_SERVER["HTTP_REFERER"]);
    }
    if (is_null($sesskey)) {    // we came here via link in email
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmsubscribe', 'partforum', format_string($partforum->name)),
                new moodle_url($PAGE->url, array('sesskey' => sesskey())), new moodle_url('/mod/partforum/view.php', array('f' => $id)));
        echo $OUTPUT->footer();
        exit;
    }
    require_sesskey();
    partforum_subscribe($user->id, $partforum->id);
    add_to_log($course->id, "partforum", "subscribe", "view.php?f=$partforum->id", $partforum->id, $cm->id);
    redirect($returnto, get_string("nowsubscribed", "partforum", $info), 1);
}
