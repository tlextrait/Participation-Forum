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
 * Edit and save a new post to a discussion
 *
 * @package mod-partforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');
require_once($CFG->libdir.'/completionlib.php');

$reply   = optional_param('reply', 0, PARAM_INT);
$partforum   = optional_param('partforum', 0, PARAM_INT);
$edit    = optional_param('edit', 0, PARAM_INT);
$delete  = optional_param('delete', 0, PARAM_INT);
$prune   = optional_param('prune', 0, PARAM_INT);
$name    = optional_param('name', '', PARAM_CLEAN);
$confirm = optional_param('confirm', 0, PARAM_INT);
$groupid = optional_param('groupid', null, PARAM_INT);

$PAGE->set_url('/mod/partforum/post.php', array(
        'reply' => $reply,
        'partforum' => $partforum,
        'edit'  => $edit,
        'delete'=> $delete,
        'prune' => $prune,
        'name'  => $name,
        'confirm'=>$confirm,
        'groupid'=>$groupid,
        ));
//these page_params will be passed as hidden variables later in the form.
$page_params = array('reply'=>$reply, 'partforum'=>$partforum, 'edit'=>$edit);

$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');

$sitecontext = context_system::instance();

if (!isloggedin() or isguestuser()) {

    if (!isloggedin() and !get_referer()) {
        // No referer+not logged in - probably coming in via email  See MDL-9052
        require_login();
    }

    if (!empty($partforum)) {      // User is starting a new discussion in a partforum
        if (! $partforum = $DB->get_record('partforum', array('id' => $partforum))) {
            print_error('invalidpartforumid', 'partforum');
        }
    } else if (!empty($reply)) {      // User is writing a new reply
        if (! $parent = partforum_get_post_full($reply)) {
            print_error('invalidparentpostid', 'partforum');
        }
        if (! $discussion = $DB->get_record('partforum_discussions', array('id' => $parent->discussion))) {
            print_error('notpartofdiscussion', 'partforum');
        }
        if (! $partforum = $DB->get_record('partforum', array('id' => $discussion->partforum))) {
            print_error('invalidpartforumid');
        }
    }
    if (! $course = $DB->get_record('course', array('id' => $partforum->course))) {
        print_error('invalidcourseid');
    }

    if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $course->id)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $partforum);
    $PAGE->set_context($modcontext);
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(get_string('noguestpost', 'partforum').'<br /><br />'.get_string('liketologin'), get_login_url(), get_referer(false));
    echo $OUTPUT->footer();
    exit;
}

require_login(0, false);   // Script is useless unless they're logged in

if (!empty($partforum)) {      // User is starting a new discussion in a partforum
    if (! $partforum = $DB->get_record("partforum", array("id" => $partforum))) {
        print_error('invalidpartforumid', 'partforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $partforum->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("partforum", $partforum->id, $course->id)) {
        print_error("invalidcoursemodule");
    }
    
    $coursecontext = context_course::instance($course->id);

    if (! partforum_user_can_post_discussion($partforum, $groupid, -1, $cm)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {
                if (enrol_selfenrol_available($course->id)) {
                    $SESSION->wantsurl = $FULLME;
                    $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                    redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
                }
            }
        }
        print_error('nopostpartforum', 'partforum');
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    if (isset($_SERVER["HTTP_REFERER"])) {
        $SESSION->fromurl = $_SERVER["HTTP_REFERER"];
    } else {
        $SESSION->fromurl = '';
    }

    // Load up the $post variable.
    
    $post = new stdClass();
    $post->course        = $course->id;
    $post->partforum         = $partforum->id;
    $post->discussion    = 0;           // ie discussion # not defined yet
    $post->parent        = 0;
    $post->subject       = '';
    $post->userid        = $USER->id;
    $post->message       = '';
    $post->messageformat = editors_get_preferred_format();
    $post->messagetrust  = 0;

    if (isset($groupid)) {
        $post->groupid = $groupid;
    } else {
        $post->groupid = groups_get_activity_group($cm);
    }

    partforum_set_return();

} else if (!empty($reply)) {      // User is writing a new reply

    if (! $parent = partforum_get_post_full($reply)) {
        print_error('invalidparentpostid', 'partforum');
    }
    if (! $discussion = $DB->get_record("partforum_discussions", array("id" => $parent->discussion))) {
        print_error('notpartofdiscussion', 'partforum');
    }
    if (! $partforum = $DB->get_record("partforum", array("id" => $discussion->partforum))) {
        print_error('invalidpartforumid', 'partforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (! $cm = get_coursemodule_from_instance("partforum", $partforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    }

    // Ensure lang, theme, etc. is set up properly. MDL-6926
    $PAGE->set_cm($cm, $course, $partforum);

    $coursecontext = context_course::instance($course->id);
    $modcontext    = context_module::instance($cm->id);

    if (! partforum_user_can_post($partforum, $discussion, $USER, $cm, $course, $modcontext)) {
        if (!isguestuser()) {
            if (!is_enrolled($coursecontext)) {  // User is a guest here!
                $SESSION->wantsurl = $FULLME;
                $SESSION->enrolcancel = $_SERVER['HTTP_REFERER'];
                redirect($CFG->wwwroot.'/enrol/index.php?id='.$course->id, get_string('youneedtoenrol'));
            }
        }
        print_error('nopostpartforum', 'partforum');
    }

    // Make sure user can post here
    if (isset($cm->groupmode) && empty($course->groupmodeforce)) {
        $groupmode =  $cm->groupmode;
    } else {
        $groupmode = $course->groupmode;
    }
    if ($groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
        if ($discussion->groupid == -1) {
            print_error('nopostpartforum', 'partforum');
        } else {
            if (!groups_is_member($discussion->groupid)) {
                print_error('nopostpartforum', 'partforum');
            }
        }
    }

    if (!$cm->visible and !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
        print_error("activityiscurrentlyhidden");
    }

    // Load up the $post variable.

    $post = new stdClass();
    $post->course      = $course->id;
    $post->partforum       = $partforum->id;
    $post->discussion  = $parent->discussion;
    $post->parent      = $parent->id;
    $post->subject     = $parent->subject;
    $post->userid      = $USER->id;
    $post->message     = '';

    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

	if($partforum->type != 'participation'){
		$strre = get_string('re', 'partforum');
	    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
	        $post->subject = $strre.' '.$post->subject;
	    }
	}

    unset($SESSION->fromdiscussion);

} else if (!empty($edit)) {  // User is editing their own post

    if (! $post = partforum_get_post_full($edit)) {
        print_error('invalidpostid', 'partforum');
    }
    if ($post->parent) {
        if (! $parent = partforum_get_post_full($post->parent)) {
            print_error('invalidparentpostid', 'partforum');
        }
    }

    if (! $discussion = $DB->get_record("partforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'partforum');
    }
    if (! $partforum = $DB->get_record("partforum", array("id" => $discussion->partforum))) {
        print_error('invalidpartforumid', 'partforum');
    }
    if (! $course = $DB->get_record("course", array("id" => $discussion->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("partforum", $partforum->id, $course->id)) {
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }

    $PAGE->set_cm($cm, $course, $partforum);

    if (!($partforum->type == 'news' && !$post->parent && $discussion->timestart > time())) {
        if (((time() - $post->created) > $CFG->maxeditingtime) and
                    !has_capability('mod/partforum:editanypost', $modcontext)) {
            print_error('maxtimehaspassed', 'partforum', '', format_time($CFG->maxeditingtime));
        }
    }
    if (($post->userid <> $USER->id) and
                !has_capability('mod/partforum:editanypost', $modcontext)) {
        print_error('cannoteditposts', 'partforum');
    }


    // Load up the $post variable.
    $post->edit   = $edit;
    $post->course = $course->id;
    $post->partforum  = $partforum->id;
    $post->groupid = ($discussion->groupid == -1) ? 0 : $discussion->groupid;

    $post = trusttext_pre_edit($post, 'message', $modcontext);

    unset($SESSION->fromdiscussion);


}else if (!empty($delete)) {  // User is deleting a post


    if (! $post = partforum_get_post_full($delete)) {
        print_error('invalidpostid', 'partforum');
    }
    if (! $discussion = $DB->get_record("partforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'partforum');
    }
    if (! $partforum = $DB->get_record("partforum", array("id" => $discussion->partforum))) {
        print_error('invalidpartforumid', 'partforum');
    }
    if (!$cm = get_coursemodule_from_instance("partforum", $partforum->id, $partforum->course)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $partforum->course))) {
        print_error('invalidcourseid');
    }

    require_login($course, false, $cm);
    $modcontext = context_module::instance($cm->id);

    if ( !(($post->userid == $USER->id && has_capability('mod/partforum:deleteownpost', $modcontext))
                || has_capability('mod/partforum:deleteanypost', $modcontext)) ) {
        print_error('cannotdeletepost', 'partforum');
        
       
    }


    $replycount = partforum_count_replies($post);

    if (!empty($confirm) && confirm_sesskey()) {    // User has confirmed the delete
        //check user capability to delete post.
        $timepassed = time() - $post->created;
        if (($timepassed > $CFG->maxeditingtime) && !has_capability('mod/partforum:deleteanypost', $modcontext)) {
            print_error("cannotdeletepost", "partforum",
                      partforum_go_back_to("discuss.php?d=$post->discussion"));
        }

        if ($post->totalscore) {
            notice(get_string('couldnotdeleteratings', 'rating'),
                    partforum_go_back_to("discuss.php?d=$post->discussion"));

        } else if ($replycount && !has_capability('mod/partforum:deleteanypost', $modcontext)) {
            print_error("couldnotdeletereplies", "partforum",
                    partforum_go_back_to("discuss.php?d=$post->discussion"));

        } else {
            if (! $post->parent) {  // post is a discussion topic as well, so delete discussion
                if ($partforum->type == 'single') {
                    notice("Sorry, but you are not allowed to delete that discussion!",
                            partforum_go_back_to("discuss.php?d=$post->discussion"));
                }
                partforum_delete_discussion($discussion, false, $course, $cm, $partforum);

                ///---- triggering event when discussion deleted--------
                 $params = array(
                    'objectid' => $discussion->id,
                    'context' => $modcontext,
                    'other' => array(
                        'partforumid' => $partforum->id,
                    )
                );

                $event = \mod_partforum\event\discussion_deleted::create($params);
                $event->add_record_snapshot('partforum_discussions', $discussion);
                $event->trigger();
                //-----------------------------------------------------------
                
                //add_to_log($discussion->course, "partforum", "delete discussion",
                //           "view.php?id=$cm->id", "$partforum->id", $cm->id);

                redirect("view.php?f=$discussion->partforum");

            } else if (partforum_delete_post($post, has_capability('mod/partforum:deleteanypost', $modcontext),
                $course, $cm, $partforum)) {

                if ($partforum->type == 'single') {
                    // Single discussion partforums are an exception. We show
                    // the partforum itself since it only has one discussion
                    // thread.
                    $discussionurl = "view.php?f=$partforum->id";
                } else {
                    $discussionurl = "discuss.php?d=$post->discussion";
                }
                
            ///--------triggering log event when post deleted---------------    
            $params = array(
            'context' => $modcontext,
            'objectid' => $post->id,
                'other' => array(
                    'discussionid' => $post->discussion,
                    'partforumid' => $partforum->id,
                    'partforumtype' => $partforum->type,
                 )
            );
            if ($post->userid !== $USER->id) {
                 $params['relateduserid'] = $post->userid;
            }
            $event = \mod_partforum\event\post_deleted::create($params);
            $event->add_record_snapshot('partforum_posts', $post);
            $event->trigger();
           
              //  add_to_log($discussion->course, "partforum", "delete post", $discussionurl, "$post->id", $cm->id);
            //------------------------------------------------------ 
                redirect(partforum_go_back_to($discussionurl));
            } else {
                print_error('errorwhiledelete', 'partforum');
            }
        }


    } else { // User just asked to delete something

        partforum_set_return();
        $PAGE->navbar->add(get_string('delete', 'partforum'));
        $PAGE->set_title($course->shortname);
        $PAGE->set_heading($course->fullname);

        if ($replycount) {
            if (!has_capability('mod/partforum:deleteanypost', $modcontext)) {      
                
                print_error("couldnotdeletereplies", "partforum",
                $CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion);
            }
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesureplural", "partforum", $replycount+1),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'#p'.$post->id);

            partforum_print_post($post, $discussion, $partforum, $cm, $course, false, false, false);

            if (empty($post->edit)) {
                $partforumtracked = partforum_tp_is_tracked($partforum);
                $posts = partforum_get_all_discussion_posts($discussion->id, "created ASC", $partforumtracked);
                partforum_print_posts_nested($course, $cm, $partforum, $discussion, $post, false, false, $partforumtracked, $posts);
            }
        } else {
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesure", "partforum", $replycount),
                         "post.php?delete=$delete&confirm=$delete",
                         $CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'#p'.$post->id);
            partforum_print_post($post, $discussion, $partforum, $cm, $course, false, false, false);
        }

    }
    echo $OUTPUT->footer();
    die;


} else if (!empty($prune)) {  // Pruning

    if (!$post = partforum_get_post_full($prune)) {
        print_error('invalidpostid', 'partforum');
    }
    if (!$discussion = $DB->get_record("partforum_discussions", array("id" => $post->discussion))) {
        print_error('notpartofdiscussion', 'partforum');
    }
    if (!$partforum = $DB->get_record("partforum", array("id" => $discussion->partforum))) {
        print_error('invalidpartforumid', 'partforum');
    }
    if ($partforum->type == 'single') {
        print_error('cannotsplit', 'partforum');
    }
    if (!$post->parent) {
        print_error('alreadyfirstpost', 'partforum');
    }
    if (!$cm = get_coursemodule_from_instance("partforum", $partforum->id, $partforum->course)) { // For the logs
        print_error('invalidcoursemodule');
    } else {
        $modcontext = context_module::instance($cm->id);
    }
    if (!has_capability('mod/partforum:splitdiscussions', $modcontext)) {
        print_error('cannotsplit', 'partforum');
    }

    if (!empty($name) && confirm_sesskey()) {    // User has confirmed the prune

        $newdiscussion = new stdClass();
        $newdiscussion->course       = $discussion->course;
        $newdiscussion->partforum        = $discussion->partforum;
        $newdiscussion->name         = $name;
        $newdiscussion->firstpost    = $post->id;
        $newdiscussion->userid       = $discussion->userid;
        $newdiscussion->groupid      = $discussion->groupid;
        $newdiscussion->assessed     = $discussion->assessed;
        $newdiscussion->usermodified = $post->userid;
        $newdiscussion->timestart    = $discussion->timestart;
        $newdiscussion->timeend      = $discussion->timeend;

        $newid = $DB->insert_record('partforum_discussions', $newdiscussion);

        $newpost = new stdClass();
        $newpost->id      = $post->id;
        $newpost->parent  = 0;
        $newpost->subject = $name;

        $DB->update_record("partforum_posts", $newpost);

        partforum_change_discussionid($post->id, $newid);

        // update last post in each discussion
        partforum_discussion_update_last_post($discussion->id);
        partforum_discussion_update_last_post($newid);
        
        
        

        add_to_log($discussion->course, "partforum", "prune post",
                       "discuss.php?d=$newid", "$post->id", $cm->id);

        redirect(partforum_go_back_to("discuss.php?d=$newid"));

    } else { // User just asked to prune something

        $course = $DB->get_record('course', array('id' => $partforum->course));

        $PAGE->navbar->add(format_string($post->subject, true), new moodle_url('/mod/partforum/discuss.php', array('d'=>$discussion->id)));
        $PAGE->navbar->add(get_string("prune", "partforum"));
        $PAGE->set_title(format_string($discussion->name).": ".format_string($post->subject));
        $PAGE->set_heading($course->fullname);
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('pruneheading', 'partforum'));
        echo '<center>';

        include('prune.html');

        partforum_print_post($post, $discussion, $partforum, $cm, $course, false, false, false);
        echo '</center>';
    }
    echo $OUTPUT->footer();
    die;
} else {
	// Unknown action
    print_error('unknowaction');
}

if (!isset($coursecontext)) {
    // Has not yet been set by post.php.
    $coursecontext = get_context_instance(CONTEXT_COURSE, $partforum->course);
}


// from now on user must be logged on properly

if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $course->id)) { // For the logs
    print_error('invalidcoursemodule');
}
$modcontext = context_module::instance($cm->id);
require_login($course, false, $cm);

if (isguestuser()) {
    // just in case
    print_error('noguest');
}

if (!isset($partforum->maxattachments)) {  // TODO - delete this once we add a field to the partforum table
    $partforum->maxattachments = 3;
}

require_once('post_form.php');

//-----sending reply parameter to form------
if($reply)
$post->reply=$reply;
//-------------------------------------------


$mform_post = new mod_partforum_post_form('post.php', array('course'=>$course, 'cm'=>$cm, 'coursecontext'=>$coursecontext, 'modcontext'=>$modcontext, 'partforum'=>$partforum, 'post'=>$post));

$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid, $modcontext->id, 'mod_partforum', 'attachment', empty($post->id)?null:$post->id);

//load data into form NOW!

if ($USER->id != $post->userid) {   // Not the original author, so add a message to the end
    $data->date = userdate($post->modified);
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$USER->id.'&course='.$post->course.'">'.
                       fullname($USER).'</a>';
        $post->message .= '<p>(<span class="edited">'.get_string('editedby', 'partforum', $data).'</span>)</p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(".get_string('editedby', 'partforum', $data).')';
    }
}

if (!empty($parent)) {
    $heading = get_string("yourreply", "partforum");
} else {
    if ($partforum->type == 'qanda') {
        $heading = get_string('yournewquestion', 'partforum');
    } else if ($partforum->type == 'participation') {
    	$heading = get_string('yournewgrouppost', 'partforum');
    } else {
        $heading = get_string('yournewtopic', 'partforum');
    }
}

if (partforum_is_subscribed($USER->id, $partforum->id)) {
    $subscribe = true;

} else if (partforum_user_has_posted($partforum->id, 0, $USER->id)) {
    $subscribe = false;

} else {
    // user not posted yet - use subscription default specified in profile
    $subscribe = !empty($USER->autosubscribe);
}

/*
* PREPARE THE POSTING FORM
*/

$draftid_editor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftid_editor, $modcontext->id, 'mod_partforum', 'post', empty($post->id) ? null : $post->id, array('subdirs'=>true), $post->message);
$mform_post->set_data(array(        'attachments'=>$draftitemid,
                                    'general'=>$heading,
                                    'subject'=>$post->subject,
                                    'message'=>array(
                                        'text'=>$currenttext,
                                        'format'=>empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
                                        'itemid'=>$draftid_editor
                                    ),
                                    'subscribe'=>$subscribe?1:0,
                                    'mailnow'=>!empty($post->mailnow),
                                    'userid'=>$post->userid,
                                    'parent'=>$post->parent,
                                    'discussion'=>$post->discussion,
                                    'course'=>$course->id) +
                                    $page_params +

                            (isset($post->format)?array(
                                    'format'=>$post->format):
                                array())+

                            (isset($discussion->timestart)?array(
                                    'timestart'=>$discussion->timestart):
                                array())+

                            (isset($discussion->timeend)?array(
                                    'timeend'=>$discussion->timeend):
                                array())+

                            (isset($post->groupid)?array(
                                    'groupid'=>$post->groupid):
                                array())+

                            (isset($discussion->id)?
                                    array('discussion'=>$discussion->id):
                                    array()));


/*
* WE ARE RECEIVING DATA FROM THE FORM
*/
if ($fromform = $mform_post->get_data()) {

    if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/mod/partforum/view.php?f=$partforum->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }

    $fromform->itemid        = $fromform->message['itemid'];
    $fromform->messageformat = $fromform->message['format'];
    $fromform->message       = $fromform->message['text'];
    // WARNING: the $fromform->message array has been overwritten, do not use it anymore!
    $fromform->messagetrust  = trusttext_trusted($modcontext);

    if ($fromform->edit) {           // Updating a post
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        //fix for bug #4314
        if (!$realpost = $DB->get_record('partforum_posts', array('id' => $fromform->id))) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }

        // if user has edit any post capability
        // or has either startnewdiscussion or reply capability and is editting own post
        // then he can proceed
        // MDL-7066
        if ( !(($realpost->userid == $USER->id && (has_capability('mod/partforum:replypost', $modcontext)
                            || has_capability('mod/partforum:startdiscussion', $modcontext))) ||
                            has_capability('mod/partforum:editanypost', $modcontext)) ) {
            print_error('cannotupdatepost', 'partforum');
        }

        $updatepost = $fromform; //realpost
        $updatepost->partforum = $partforum->id;
        if (!partforum_update_post($updatepost, $mform_post, $message)) {
            print_error("couldnotupdate", "partforum", $errordestination);
        }

        // MDL-11818
        if (($partforum->type == 'single') && ($updatepost->parent == '0')){ // updating first post of single discussion type -> updating partforum intro
            $partforum->intro = $updatepost->message;
            $partforum->timemodified = time();
            $DB->update_record("partforum", $partforum);
        }

        $timemessage = 2;
        if (!empty($message)) { // if we're printing stuff about the file upload
            $timemessage = 4;
        }
        $message .= '<br />'.get_string("postupdated", "partforum");

        if ($subscribemessage = partforum_post_subscription($fromform, $partforum)) {
            $timemessage = 4;
        }
        if ($partforum->type == 'single') {
            // Single discussion partforums are an exception. We show
            // the partforum itself since it only has one discussion
            // thread.
            $discussionurl = "view.php?f=$partforum->id";
        } else {
            $discussionurl = "discuss.php?d=$discussion->id#p$fromform->id";
        }
        
        //---triggering log event (added by hema)
         $params = array(
            'context' => $modcontext,
            'objectid' => $fromform->id,
            'other' => array(
                'discussionid' => $discussion->id,
                'partforumid' => $partforum->id,
                'partforumtype' => $partforum->type,
            )
        );

        if ($realpost->userid !== $USER->id) {
            $params['relateduserid'] = $realpost->userid;
        }

        $event = \mod_partforum\event\post_updated::create($params);
        $event->add_record_snapshot('partforum_discussions', $discussion);
        $event->trigger();
        
        //------------------------------------------------------------------
        //add_to_log($course->id, "partforum", "update post",
        //        "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

        redirect(partforum_go_back_to("$discussionurl"), $message.$subscribemessage, $timemessage);

        exit;


    } else if ($fromform->discussion) { // Adding a new post to an existing discussion
        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->partforum=$partforum->id;
        
        
        if ($fromform->id = partforum_add_new_post($addpost, $mform_post, $message)) {

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($subscribemessage = partforum_post_subscription($fromform, $partforum)) {
                $timemessage = 4;
            }

            if (!empty($fromform->mailnow)) {
                $message .= get_string("postmailnow", "partforum");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "partforum") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "partforum", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($partforum->type == 'single') {
                // Single discussion partforums are an exception. We show
                // the partforum itself since it only has one discussion
                // thread.
                $discussionurl = "view.php?f=$partforum->id";
            } else {
                $discussionurl = "discuss.php?d=$discussion->id";
            }
            
            //----inserting into log table when post created
             $params = array(
                'context' => $modcontext,
                'objectid' => $fromform->id,
                'other' => array(
                    'discussionid' => $discussion->id,
                    'partforumid' => $partforum->id,
                    'partforumtype' => $partforum->type,
                )
            );
            $event = \mod_partforum\event\post_created::create($params);
            $event->add_record_snapshot('partforum_posts', $fromform);
            $event->add_record_snapshot('partforum_discussions', $discussion);
            $event->trigger();
            //-------------------------------------------------------------
            
            // add_to_log($course->id, "partforum", "add post",
            //          "$discussionurl&amp;parent=$fromform->id", "$fromform->id", $cm->id);

            // Update completion state
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($partforum->completionreplies || $partforum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(partforum_go_back_to("$discussionurl#p$fromform->id"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "partforum", $errordestination);
        }
        exit;

    } else {                     // Adding a new discussion
        if (!partforum_user_can_post_discussion($partforum, $fromform->groupid, -1, $cm, $modcontext)) {
            print_error('cannotcreatediscussion', 'partforum');
        }
        if (empty($fromform->groupid)) {
            $fromform->groupid = -1;
        }

        $fromform->mailnow = empty($fromform->mailnow) ? 0 : 1;

        $discussion = $fromform;
        $discussion->name    = $fromform->subject;

        $newstopic = false;
        if ($partforum->type == 'news' && !$fromform->parent) {
            $newstopic = true;
        }
        $discussion->timestart = $fromform->timestart;
        $discussion->timeend = $fromform->timeend;

        $message = '';
        if ($discussion->id = partforum_add_discussion($discussion, $mform_post, $message)) {
            
            //-----triggering log event when discussion created
             $params = array(
                    'context' => $modcontext,
                    'objectid' => $discussion->id,
                    'other' => array(
                        'partforumid' => $partforum->id,
                    )
                );
             
                $event = \mod_partforum\event\discussion_created::create($params);
                $event->add_record_snapshot('partforum_discussions', $discussion);
                $event->trigger();
            //----------------------------------------------------------
           // add_to_log($course->id, "partforum", "add discussion",
           //         "discuss.php?d=$discussion->id", "$discussion->id", $cm->id);

            $timemessage = 2;
            if (!empty($message)) { // if we're printing stuff about the file upload
                $timemessage = 4;
            }

            if ($fromform->mailnow) {
                $message .= get_string("postmailnow", "partforum");
                $timemessage = 4;
            } else {
                $message .= '<p>'.get_string("postaddedsuccess", "partforum") . '</p>';
                $message .= '<p>'.get_string("postaddedtimeleft", "partforum", format_time($CFG->maxeditingtime)) . '</p>';
            }

            if ($subscribemessage = partforum_post_subscription($discussion, $partforum)) {
                $timemessage = 4;
            }

            // Update completion status
            $completion=new completion_info($course);
            if($completion->is_enabled($cm) &&
                ($partforum->completiondiscussions || $partforum->completionposts)) {
                $completion->update_state($cm,COMPLETION_COMPLETE);
            }

            redirect(partforum_go_back_to("view.php?f=$fromform->partforum"), $message.$subscribemessage, $timemessage);

        } else {
            print_error("couldnotadd", "partforum", $errordestination);
        }

        exit;
    }
}



// To get here they need to edit a post, and the $post
// variable will be loaded with all the particulars,
// so bring up the form.

// $course, $partforum are defined.  $discussion is for edit and reply only.

if ($post->discussion) {
    if (! $toppost = $DB->get_record("partforum_posts", array("discussion" => $post->discussion, "parent" => 0))) {
        print_error('cannotfindparentpost', 'partforum', '', $post->id);
    }
} else {
    $toppost->subject = ($partforum->type == "news") ? get_string("addanewtopic", "partforum") :
                                                   get_string("addanewdiscussion", "partforum");
}

if (empty($post->edit)) {
    $post->edit = '';
}

if (empty($discussion->name)) {
    if (empty($discussion)) {
        $discussion = new stdClass();
    }
    $discussion->name = $partforum->name;
}
if ($partforum->type == 'single') {
    // There is only one discussion thread for this partforum type. We should
    // not show the discussion name (same as partforum name in this case) in
    // the breadcrumbs.
    $strdiscussionname = '';
} else {
    // Show the discussion name in the breadcrumbs.
    $strdiscussionname = format_string($discussion->name).':';
}

$forcefocus = empty($reply) ? NULL : 'message';

if (!empty($discussion->id)) {
    $PAGE->navbar->add(format_string($toppost->subject, true), "discuss.php?d=$discussion->id");
}

if ($post->parent) {
    $PAGE->navbar->add(get_string('reply', 'partforum'));
}

if ($edit) {
    $PAGE->navbar->add(get_string('edit', 'partforum'));
}

$PAGE->set_title("$course->shortname: $strdiscussionname ".format_string($toppost->subject));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

// checkup
if (!empty($parent) && !partforum_user_can_see_post($partforum, $discussion, $post, null, $cm)) {
    print_error('cannotreply', 'partforum');
}
if (empty($parent) && empty($edit) && !partforum_user_can_post_discussion($partforum, $groupid, -1, $cm, $modcontext)) {
    print_error('cannotcreatediscussion', 'partforum');
}

if ($partforum->type == 'qanda'
            && !has_capability('mod/partforum:viewqandawithoutposting', $modcontext)
            && !empty($discussion->id)
            && !partforum_user_has_posted($partforum->id, $discussion->id, $USER->id)) {
    echo $OUTPUT->notification(get_string('qandanotify','partforum'));
}

partforum_check_throttling($partforum, $cm);

if (!empty($parent)) {
    if (! $discussion = $DB->get_record('partforum_discussions', array('id' => $parent->discussion))) {
        print_error('notpartofdiscussion', 'partforum');
    }

    partforum_print_post($parent, $discussion, $partforum, $cm, $course, false, false, false);
    if (empty($post->edit)) {
        if ($partforum->type != 'qanda' || partforum_user_can_see_discussion($partforum, $discussion, $modcontext)) {
            $partforumtracked = partforum_tp_is_tracked($partforum);
            $posts = partforum_get_all_discussion_posts($discussion->id, "created ASC", $partforumtracked);
            partforum_print_posts_threaded($course, $cm, $partforum, $discussion, $parent, 0, false, $partforumtracked, $posts);
        }
    }
} else {
    if (!empty($partforum->intro)) {
        echo $OUTPUT->box(format_module_intro('partforum', $partforum, $cm->id), 'generalbox', 'intro');
    }
}

$mform_post->display();

echo $OUTPUT->footer();
