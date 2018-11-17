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
 * @package mod-partforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Include required files */

require_once($CFG->libdir.'/filelib.php');
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->dirroot.'/user/selector/lib.php');

/// CONSTANTS ///////////////////////////////////////////////////////////

define('PARTFORUM_MODE_FLATOLDEST', 1);
define('PARTFORUM_MODE_FLATNEWEST', -1);
define('PARTFORUM_MODE_THREADED', 2);
define('PARTFORUM_MODE_NESTED', 3);

define('PARTFORUM_CHOOSESUBSCRIBE', 0);
define('PARTFORUM_FORCESUBSCRIBE', 1);
define('PARTFORUM_INITIALSUBSCRIBE', 2);
define('PARTFORUM_DISALLOWSUBSCRIBE',3);

define('PARTFORUM_TRACKING_OFF', 0);
define('PARTFORUM_TRACKING_OPTIONAL', 1);
define('PARTFORUM_TRACKING_ON', 2);

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @global object
 * @global object
 * @param object $partforum add partforum instance (with magic quotes)
 * @return int intance id
 */
function partforum_add_instance($partforum, $mform) {
    global $CFG, $DB;

    $partforum->timemodified = time();

    if (empty($partforum->assessed)) {
        $partforum->assessed = 0;
    }

    if (empty($partforum->ratingtime) or empty($partforum->assessed)) {
        $partforum->assesstimestart  = 0;
        $partforum->assesstimefinish = 0;
    }
    
    $partforum->id = $DB->insert_record('partforum', $partforum);
    $modcontext = context_module::instance($partforum->coursemodule);

    if ($partforum->type == 'single') {  // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $partforum->course;
        $discussion->partforum         = $partforum->id;
        $discussion->name          = $partforum->name;
        $discussion->assessed      = $partforum->assessed;
        $discussion->message       = $partforum->intro;
        $discussion->messageformat = $partforum->introformat;
        $discussion->messagetrust  = trusttext_trusted(context_course::instance($partforum->course));
        $discussion->mailnow       = false;
        $discussion->groupid       = -1;

        $message = '';

        $discussion->id = partforum_add_discussion($discussion, null, $message);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // ugly hack - we need to copy the files somehow
            $discussion = $DB->get_record('partforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('partforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
            $DB->set_field('partforum_posts', 'message', $post->message, array('id'=>$post->id));
        }
    }

    if ($partforum->forcesubscribe == PARTFORUM_INITIALSUBSCRIBE) {
    /// all users should be subscribed initially
    /// Note: partforum_get_potential_subscribers should take the partforum context,
    /// but that does not exist yet, becuase the partforum is only half build at this
    /// stage. However, because the partforum is brand new, we know that there are
    /// no role assignments or overrides in the partforum context, so using the
    /// course context gives the same list of users.
        $users = partforum_get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            partforum_subscribe($user->id, $partforum->id);
        }
    }

    partforum_grade_item_update($partforum);

    return $partforum->id;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $partforum partforum instance (with magic quotes)
 * @return bool success
 */
function partforum_update_instance($partforum, $mform) {
    global $DB, $OUTPUT, $USER;

    $partforum->timemodified = time();
    $partforum->id           = $partforum->instance;

    if (empty($partforum->assessed)) {
        $partforum->assessed = 0;
    }

    if (empty($partforum->ratingtime) or empty($partforum->assessed)) {
        $partforum->assesstimestart  = 0;
        $partforum->assesstimefinish = 0;
    }
    
    $oldpartforum = $DB->get_record('partforum', array('id'=>$partforum->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire partforum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($oldpartforum->assessed<>$partforum->assessed) or ($oldpartforum->scale<>$partforum->scale)) {
        partforum_update_grades($partforum); // recalculate grades for the partforum
    }

    if ($partforum->type == 'single') {  // Update related discussion and post.
        if (! $discussion = $DB->get_record('partforum_discussions', array('partforum'=>$partforum->id))) {
            if ($discussions = $DB->get_records('partforum_discussions', array('partforum'=>$partforum->id), 'timemodified ASC')) {
                echo $OUTPUT->notification('Warning! There is more than one discussion in this partforum - using the most recent');
                $discussion = array_pop($discussions);
            } else {
                // try to recover by creating initial discussion - MDL-16262
                $discussion = new stdClass();
                $discussion->course          = $partforum->course;
                $discussion->partforum           = $partforum->id;
                $discussion->name            = $partforum->name;
                $discussion->assessed        = $partforum->assessed;
                $discussion->message         = $partforum->intro;
                $discussion->messageformat   = $partforum->introformat;
                $discussion->messagetrust    = true;
                $discussion->mailnow         = false;
                $discussion->groupid         = -1;

                $message = '';

                partforum_add_discussion($discussion, null, $message);

                if (! $discussion = $DB->get_record('partforum_discussions', array('partforum'=>$partforum->id))) {
                    print_error('cannotadd', 'partforum');
                }
            }
        }
        if (! $post = $DB->get_record('partforum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'partforum');
        }

        $cm         = get_coursemodule_from_instance('partforum', $partforum->id);
        $modcontext = context_module::instance($cm->id, MUST_EXIST);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // ugly hack - we need to copy the files somehow
            $discussion = $DB->get_record('partforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('partforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
        }

        $post->subject       = $partforum->name;
        $post->message       = $partforum->intro;
        $post->messageformat = $partforum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $partforum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities

        $DB->update_record('partforum_posts', $post);
        $discussion->name = $partforum->name;
        $DB->update_record('partforum_discussions', $discussion);
    }

    $DB->update_record('partforum', $partforum);

    partforum_grade_item_update($partforum);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id partforum instance id
 * @return bool success
 */
function partforum_delete_instance($id) {
    global $DB;

    if (!$partforum = $DB->get_record('partforum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = context_module::instance($cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('partforum_discussions', array('partforum'=>$partforum->id))) {
        foreach ($discussions as $discussion) {
            if (!partforum_delete_discussion($discussion, true, $course, $cm, $partforum)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('partforum_subscriptions', array('partforum'=>$partforum->id))) {
        $result = false;
    }

    partforum_tp_delete_read_records(-1, -1, -1, $partforum->id);

    if (!$DB->delete_records('partforum', array('id'=>$partforum->id))) {
        $result = false;
    }

    partforum_grade_item_delete($partforum);

    return $result;
}


/**
 * Indicates API features that the partforum supports.
 *
 * @uses FEATURE_GROUPS
 * @uses FEATURE_GROUPINGS
 * @uses FEATURE_GROUPMEMBERSONLY
 * @uses FEATURE_MOD_INTRO
 * @uses FEATURE_COMPLETION_TRACKS_VIEWS
 * @uses FEATURE_COMPLETION_HAS_RULES
 * @uses FEATURE_GRADE_HAS_GRADE
 * @uses FEATURE_GRADE_OUTCOMES
 * @param string $feature
 * @return mixed True if yes (some features may use other values)
 */
function partforum_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_RATE:                    return true;
        case FEATURE_BACKUP_MOODLE2:          return true;

        default: return null;
    }
}


/**
 * Obtains the automatic completion state for this partforum based on any conditions
 * in partforum settings.
 *
 * @global object
 * @global object
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function partforum_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get partforum details
    if (!($partforum=$DB->get_record('partforum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find partforum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'partforumid'=>$partforum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {partforum_posts} fp
    INNER JOIN {partforum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.partforum=:partforumid";

    if ($partforum->completiondiscussions) {
        $value = $partforum->completiondiscussions <=
                 $DB->count_records('partforum_discussions',array('partforum'=>$partforum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($partforum->completionreplies) {
        $value = $partforum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($partforum->completionposts) {
        $value = $partforum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }

    return $result;
}


/**
 * Function to be run periodically according to the moodle cron
 * Finds all posts that have yet to be mailed out, and mails them
 * out to all subscribers
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses CONTEXT_COURSE
 * @uses SITEID
 * @uses FORMAT_PLAIN
 * @return void
 */
function partforum_cron() {
    global $CFG, $USER, $DB;

    $site = get_site();

    // all users that are subscribed to any post that needs sending
    $users = array();

    // status arrays
    $mailcount  = array();
    $errorcount = array();

    // caches
    $discussions     = array();
    $partforums          = array();
    $courses         = array();
    $coursemodules   = array();
    $subscribedusers = array();


    // Posts older than 2 days will not be mailed.  This is to avoid the problem where
    // cron has not been running for a long time, and then suddenly people are flooded
    // with mail from the past few weeks or months
    $timenow   = time();
    $endtime   = $timenow - $CFG->maxeditingtime;
    $starttime = $endtime - 48 * 3600;   // Two days earlier

    if ($posts = partforum_get_unmailed_posts($starttime, $endtime, $timenow)) {
        // Mark them all now as being mailed.  It's unlikely but possible there
        // might be an error later so that a post is NOT actually mailed out,
        // but since mail isn't crucial, we can accept this risk.  Doing it now
        // prevents the risk of duplicated mails, which is a worse problem.

        if (!partforum_mark_old_posts_as_mailed($endtime)) {
            mtrace('Errors occurred while trying to mark some posts as being mailed.');
            return false;  // Don't continue trying to mail them, in case we are in a cron loop
        }

        // checking post validity, and adding users to loop through later
        foreach ($posts as $pid => $post) {

            $discussionid = $post->discussion;
            if (!isset($discussions[$discussionid])) {
                if ($discussion = $DB->get_record('partforum_discussions', array('id'=> $post->discussion))) {
                    $discussions[$discussionid] = $discussion;
                } else {
                    mtrace('Could not find discussion '.$discussionid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $partforumid = $discussions[$discussionid]->partforum;
            if (!isset($partforums[$partforumid])) {
                if ($partforum = $DB->get_record('partforum', array('id' => $partforumid))) {
                    $partforums[$partforumid] = $partforum;
                } else {
                    mtrace('Could not find partforum '.$partforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $partforums[$partforumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$partforumid])) {
                if ($cm = get_coursemodule_from_instance('partforum', $partforumid, $courseid)) {
                    $coursemodules[$partforumid] = $cm;
                } else {
                    mtrace('Could not find course module for partforum '.$partforumid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each partforum
            if (!isset($subscribedusers[$partforumid])) {
                $modcontext = context_module::instance($coursemodules[$partforumid]->id);
                if ($subusers = partforum_subscribed_users($courses[$courseid], $partforums[$partforumid], 0, $modcontext, "u.*")) {
                    foreach ($subusers as $postuser) {
                        unset($postuser->description); // not necessary
                        // this user is subscribed to this partforum
                        $subscribedusers[$partforumid][$postuser->id] = $postuser->id;
                        // this user is a user we have to process later
                        $users[$postuser->id] = $postuser;
                    }
                    unset($subusers); // release memory
                }
            }

            $mailcount[$pid] = 0;
            $errorcount[$pid] = 0;
        }
    }

    if ($users && $posts) {

        $urlinfo = parse_url($CFG->wwwroot);
        $hostname = $urlinfo['host'];

        foreach ($users as $userto) {

            @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

            // set this so that the capabilities are cached, and environment matches receiving user
            cron_setup_user($userto);

            mtrace('Processing user '.$userto->id);

            // init caches
            $userto->viewfullnames = array();
            $userto->canpost       = array();
            $userto->markposts     = array();

            // reset the caches
            foreach ($coursemodules as $partforumid=>$unused) {
                $coursemodules[$partforumid]->cache       = new stdClass();
                $coursemodules[$partforumid]->cache->caps = array();
                unset($coursemodules[$partforumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, partforum, course
                $discussion = $discussions[$post->discussion];
                $partforum      = $partforums[$discussion->partforum];
                $course     = $courses[$partforum->course];
                $cm         =& $coursemodules[$partforum->id];

                // Do some checks  to see if we can bail out now
                // Only active enrolled users are in the list of subscribers
                if (!isset($subscribedusers[$partforum->id][$userto->id])) {
                    continue; // user does not subscribe to this partforum
                }

                // Don't send email if the partforum is Q&A and the user has not posted
                if ($partforum->type == 'qanda' && !partforum_get_user_posted_time($discussion->id, $userto->id)) {
                    mtrace('Did not email '.$userto->id.' because user has not posted in discussion');
                    continue;
                }

                // Get info about the sending user
                if (array_key_exists($post->userid, $users)) { // we might know him/her already
                    $userfrom = $users[$post->userid];
                } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                    unset($userfrom->description); // not necessary
                    $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                } else {
                    mtrace('Could not find user '.$post->userid);
                    continue;
                }

                //if we want to check that userto and userfrom are not the same person this is probably the spot to do it

                // setup global $COURSE properly - needed for roles and languages
                cron_setup_user($userto, $course);

                // Fill caches
                if (!isset($userto->viewfullnames[$partforum->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->viewfullnames[$partforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = context_module::instance($cm->id);
                    $userto->canpost[$discussion->id] = partforum_user_can_post($partforum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$partforum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        $users[$userfrom->id]->groups = array();
                    }
                    $userfrom->groups[$partforum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    $users[$userfrom->id]->groups[$partforum->id] = $userfrom->groups[$partforum->id];
                }

                // Make sure groups allow this user to see this email
                if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
                    if (!groups_group_exists($discussion->groupid)) { // Can't find group
                        continue;                           // Be safe and don't send it to anyone
                    }

                    if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                        // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
                        continue;
                    }
                }

                // Make sure we're allowed to see it...
                if (!partforum_user_can_see_post($partforum, $discussion, $post, NULL, $cm)) {
                    mtrace('user '.$userto->id. ' can not see '.$post->id);
                    continue;
                }

                // OK so we need to send the email.

                // Does the user want this post in a digest?  If so postpone it for now.
                if ($userto->maildigest > 0) {
                    // This user wants the mails to be in digest form
                    $queue = new stdClass();
                    $queue->userid       = $userto->id;
                    $queue->discussionid = $discussion->id;
                    $queue->postid       = $post->id;
                    $queue->timemodified = $post->created;
                    $DB->insert_record('partforum_queue', $queue);
                    continue;
                }


                // Prepare to actually send the post now, and build up the content

                $cleanpartforumname = str_replace('"', "'", strip_tags(format_string($partforum->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleanpartforumname.'" <moodlepartforum'.$partforum->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/partforum/view.php?f='.$partforum->id,
                           'Message-ID: <moodlepost'.$post->id.'@'.$hostname.'>',
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );

                if ($post->parent) {  // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: <moodlepost'.$post->parent.'@'.$hostname.'>';
                    $userfrom->customheaders[] = 'References: <moodlepost'.$post->parent.'@'.$hostname.'>';
                }

                $postsubject = "$course->shortname: ".format_string($post->subject,true);
                $posttext = partforum_make_mail_text($course, $cm, $partforum, $discussion, $post, $userfrom, $userto);
                $posthtml = partforum_make_mail_html($course, $cm, $partforum, $discussion, $post, $userfrom, $userto);

                // Send the post now!

                mtrace('Sending ', '');

                $eventdata = new stdClass();
                $eventdata->component        = 'mod_partforum';
                $eventdata->name             = 'posts';
                $eventdata->userfrom         = $userfrom;
                $eventdata->userto           = $userto;
                $eventdata->subject          = $postsubject;
                $eventdata->fullmessage      = $posttext;
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml  = $posthtml;
                $eventdata->notification = 1;

                $smallmessagestrings = new stdClass();
                $smallmessagestrings->user = fullname($userfrom);
                $smallmessagestrings->partforumname = "{$course->shortname}: ".format_string($partforum->name,true).": ".$discussion->name;
                $smallmessagestrings->message = $post->message;
                //make sure strings are in message recipients language
                $eventdata->smallmessage = get_string_manager()->get_string('smallmessage', 'partforum', $smallmessagestrings, $userto->lang);

                $eventdata->contexturl = "{$CFG->wwwroot}/mod/partforum/discuss.php?d={$discussion->id}#p{$post->id}";
                $eventdata->contexturlname = $discussion->name;

                $mailresult = message_send($eventdata);
                if (!$mailresult){
                    mtrace("Error: mod/partforum/lib.php partforum_cron(): Could not send out mail for id $post->id to user $userto->id".
                         " ($userto->email) .. not trying again.");
                    add_to_log($course->id, 'partforum', 'mail error', "discuss.php?d=$discussion->id#p$post->id",
                               substr(format_string($post->subject,true),0,30), $cm->id, $userto->id);
                    $errorcount[$post->id]++;
                } else {
                    $mailcount[$post->id]++;

                // Mark post as read if partforum_usermarksread is set off
                    if (!$CFG->partforum_usermarksread) {
                        $userto->markposts[$post->id] = $post->id;
                    }
                }

                mtrace('post '.$post->id. ': '.$post->subject);
            }

            // mark processed posts as read
            partforum_tp_mark_posts_read($userto, $userto->markposts);
        }
    }

    if ($posts) {
        foreach ($posts as $post) {
            mtrace($mailcount[$post->id]." users were sent post $post->id, '$post->subject'");
            if ($errorcount[$post->id]) {
                $DB->set_field("partforum_posts", "mailed", "2", array("id" => "$post->id"));
            }
        }
    }

    // release some memory
    unset($subscribedusers);
    unset($mailcount);
    unset($errorcount);

    cron_setup_user();

    $sitetimezone = $CFG->timezone;

    // Now see if there are any digest mails waiting to be sent, and if we should send them

    mtrace('Starting digest processing...');

    @set_time_limit(300); // terminate if not able to fetch all digests in 5 minutes

    if (!isset($CFG->digestmailtimelast)) {    // To catch the first time
        set_config('digestmailtimelast', 0);
    }

    $timenow = time();
    $digesttime = usergetmidnight($timenow, $sitetimezone) + ($CFG->digestmailtime * 3600);

    // Delete any really old ones (normally there shouldn't be any)
    $weekago = $timenow - (7 * 24 * 3600);
    $DB->delete_records_select('partforum_queue', "timemodified < ?", array($weekago));
    mtrace ('Cleaned old digest records');

    if ($CFG->digestmailtimelast < $digesttime and $timenow > $digesttime) {

        mtrace('Sending partforum digests: '.userdate($timenow, '', $sitetimezone));

        $digestposts_rs = $DB->get_recordset_select('partforum_queue', "timemodified < ?", array($digesttime));

        if ($digestposts_rs->valid()) {

            // We have work to do
            $usermailcount = 0;

            //caches - reuse the those filled before too
            $discussionposts = array();
            $userdiscussions = array();

            foreach ($digestposts_rs as $digestpost) {
                if (!isset($users[$digestpost->userid])) {
                    if ($user = $DB->get_record('user', array('id' => $digestpost->userid))) {
                        $users[$digestpost->userid] = $user;
                    } else {
                        continue;
                    }
                }
                $postuser = $users[$digestpost->userid];

                if (!isset($posts[$digestpost->postid])) {
                    if ($post = $DB->get_record('partforum_posts', array('id' => $digestpost->postid))) {
                        $posts[$digestpost->postid] = $post;
                    } else {
                        continue;
                    }
                }
                $discussionid = $digestpost->discussionid;
                if (!isset($discussions[$discussionid])) {
                    if ($discussion = $DB->get_record('partforum_discussions', array('id' => $discussionid))) {
                        $discussions[$discussionid] = $discussion;
                    } else {
                        continue;
                    }
                }
                $partforumid = $discussions[$discussionid]->partforum;
                if (!isset($partforums[$partforumid])) {
                    if ($partforum = $DB->get_record('partforum', array('id' => $partforumid))) {
                        $partforums[$partforumid] = $partforum;
                    } else {
                        continue;
                    }
                }

                $courseid = $partforums[$partforumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$partforumid])) {
                    if ($cm = get_coursemodule_from_instance('partforum', $partforumid, $courseid)) {
                        $coursemodules[$partforumid] = $cm;
                    } else {
                        continue;
                    }
                }
                $userdiscussions[$digestpost->userid][$digestpost->discussionid] = $digestpost->discussionid;
                $discussionposts[$digestpost->discussionid][$digestpost->postid] = $digestpost->postid;
            }
            $digestposts_rs->close(); /// Finished iteration, let's close the resultset

            // Data collected, start sending out emails to each user
            foreach ($userdiscussions as $userid => $thesediscussions) {

                @set_time_limit(120); // terminate if processing of any account takes longer than 2 minutes

                cron_setup_user();

                mtrace(get_string('processingdigest', 'partforum', $userid), '... ');

                // First of all delete all the queue entries for this user
                $DB->delete_records_select('partforum_queue', "userid = ? AND timemodified < ?", array($userid, $digesttime));
                $userto = $users[$userid];

                // Override the language and timezone of the "current" user, so that
                // mail is customised for the receiver.
                cron_setup_user($userto);

                // init caches
                $userto->viewfullnames = array();
                $userto->canpost       = array();
                $userto->markposts     = array();

                $postsubject = get_string('digestmailsubject', 'partforum', format_string($site->shortname, true));

                $headerdata = new stdClass();
                $headerdata->sitename = format_string($site->fullname, true);
                $headerdata->userprefs = $CFG->wwwroot.'/user/edit.php?id='.$userid.'&amp;course='.$site->id;

                $posttext = get_string('digestmailheader', 'partforum', $headerdata)."\n\n";
                $headerdata->userprefs = '<a target="_blank" href="'.$headerdata->userprefs.'">'.get_string('digestmailprefs', 'partforum').'</a>';

                $posthtml = "<head>";
                $posthtml .= "</head>\n<body id=\"email\">\n";
                $posthtml .= '<p>'.get_string('digestmailheader', 'partforum', $headerdata).'</p><br /><hr size="1" noshade="noshade" />';

                foreach ($thesediscussions as $discussionid) {

                    @set_time_limit(120);   // to be reset for each post

                    $discussion = $discussions[$discussionid];
                    $partforum      = $partforums[$discussion->partforum];
                    $course     = $courses[$partforum->course];
                    $cm         = $coursemodules[$partforum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$partforum->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->viewfullnames[$partforum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = context_module::instance($cm->id);
                        $userto->canpost[$discussion->id] = partforum_user_can_post($partforum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strpartforums      = get_string('partforums', 'partforum');
                    $canunsubscribe = ! partforum_is_forcesubscribed($partforum);
                    $canreply       = $userto->canpost[$discussion->id];

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$course->shortname -> $strpartforums -> ".format_string($partforum->name,true);
                    if ($discussion->name != $partforum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/partforum/index.php?id=$course->id\">$strpartforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/partforum/view.php?f=$partforum->id\">".format_string($partforum->name,true)."</a>";
                    if ($discussion->name == $partforum->name) {
                        $posthtml .= "</font></p>";
                    } else {
                        $posthtml .= " -> <a target=\"_blank\" href=\"$CFG->wwwroot/mod/partforum/discuss.php?d=$discussion->id\">".format_string($discussion->name,true)."</a></font></p>";
                    }
                    $posthtml .= '<p>';

                    $postsarray = $discussionposts[$discussionid];
                    sort($postsarray);

                    foreach ($postsarray as $postid) {
                        $post = $posts[$postid];

                        if (array_key_exists($post->userid, $users)) { // we might know him/her already
                            $userfrom = $users[$post->userid];
                        } else if ($userfrom = $DB->get_record('user', array('id' => $post->userid))) {
                            $users[$userfrom->id] = $userfrom; // fetch only once, we can add it to user list, it will be skipped anyway
                        } else {
                            mtrace('Could not find user '.$post->userid);
                            continue;
                        }

                        if (!isset($userfrom->groups[$partforum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                $users[$userfrom->id]->groups = array();
                            }
                            $userfrom->groups[$partforum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            $users[$userfrom->id]->groups[$partforum->id] = $userfrom->groups[$partforum->id];
                        }

                        $userfrom->customheaders = array ("Precedence: Bulk");

                        if ($userto->maildigest == 2) {
                            // Subjects only
                            $by = new stdClass();
                            $by->name = fullname($userfrom);
                            $by->date = userdate($post->modified);
                            $posttext .= "\n".format_string($post->subject,true).' '.get_string("bynameondate", "partforum", $by);
                            $posttext .= "\n---------------------------------------------------------------------";

                            $by->name = "<a target=\"_blank\" href=\"$CFG->wwwroot/user/view.php?id=$userfrom->id&amp;course=$course->id\">$by->name</a>";
                            $posthtml .= '<div><a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$discussion->id.'#p'.$post->id.'">'.format_string($post->subject,true).'</a> '.get_string("bynameondate", "partforum", $by).'</div>';

                        } else {
                            // The full treatment
                            $posttext .= partforum_make_mail_text($course, $cm, $partforum, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= partforum_make_mail_post($course, $cm, $partforum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->partforum_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    if ($canunsubscribe) {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\"><a href=\"$CFG->wwwroot/mod/partforum/subscribe.php?id=$partforum->id\">".get_string("unsubscribe", "partforum")."</a></font></div>";
                    } else {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\">".get_string("everyoneissubscribed", "partforum")."</font></div>";
                    }
                    $posthtml .= '<hr size="1" noshade="noshade" /></p>';
                }
                $posthtml .= '</body>';

                if (empty($userto->mailformat) || $userto->mailformat != 1) {
                    // This user DOESN'T want to receive HTML
                    $posthtml = '';
                }

                $attachment = $attachname='';
                $usetrueaddress = true;
                //directly email partforum digests rather than sending them via messaging
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname, $usetrueaddress, $CFG->partforum_replytouser);

                if (!$mailresult) {
                    mtrace("ERROR!");
                    echo "Error: mod/partforum/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    add_to_log($course->id, 'partforum', 'mail digest error', '', '', $cm->id, $userto->id);
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if partforum_usermarksread is set off
                    partforum_tp_mark_posts_read($userto, $userto->markposts);
                }
            }
        }
    /// We have finishied all digest emails, update $CFG->digestmailtimelast
        set_config('digestmailtimelast', $timenow);
    }

    cron_setup_user();

    if (!empty($usermailcount)) {
        mtrace(get_string('digestsentusers', 'partforum', $usermailcount));
    }

    if (!empty($CFG->partforum_lastreadclean)) {
        $timenow = time();
        if ($CFG->partforum_lastreadclean + (24*3600) < $timenow) {
            set_config('partforum_lastreadclean', $timenow);
            mtrace('Removing old partforum read tracking info...');
            partforum_tp_clean_read_records();
        }
    } else {
        set_config('partforum_lastreadclean', time());
    }


    return true;
}

/**
 * Builds and returns the body of the email notification in plain text.
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param object $course
 * @param object $cm
 * @param object $partforum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @param boolean $bare
 * @return string The email body in plain text format.
 */
function partforum_make_mail_text($course, $cm, $partforum, $discussion, $post, $userfrom, $userto, $bare = false) {
    global $CFG, $USER;

    $modcontext = context_module::instance($cm->id);

    if (!isset($userto->viewfullnames[$partforum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$partforum->id];
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = partforum_user_can_post($partforum, $discussion, $userto, $cm, $course, $modcontext);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $by = New stdClass;
    $by->name = fullname($userfrom, $viewfullnames);
    $by->date = userdate($post->modified, "", $userto->timezone);

    $strbynameondate = get_string('bynameondate', 'partforum', $by);

    $strpartforums = get_string('partforums', 'partforum');

    $canunsubscribe = ! partforum_is_forcesubscribed($partforum);

    $posttext = '';

    if (!$bare) {
        $posttext  = "$course->shortname -> $strpartforums -> ".format_string($partforum->name,true);

        if ($discussion->name != $partforum->name) {
            $posttext  .= " -> ".format_string($discussion->name,true);
        }
    }

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_partforum', 'post', $post->id);

    $posttext .= "\n---------------------------------------------------------------------\n";
    $posttext .= format_string($post->subject,true);
    if ($bare) {
        $posttext .= " ($CFG->wwwroot/mod/partforum/discuss.php?d=$discussion->id#p$post->id)";
    }
    $posttext .= "\n".$strbynameondate."\n";
    $posttext .= "---------------------------------------------------------------------\n";
    $posttext .= format_text_email($post->message, $post->messageformat);
    $posttext .= "\n\n";
    $posttext .= partforum_print_attachments($post, $cm, "text");

    if (!$bare && $canreply) {
        $posttext .= "---------------------------------------------------------------------\n";
        $posttext .= get_string("postmailinfo", "partforum", $course->shortname)."\n";
        $posttext .= "$CFG->wwwroot/mod/partforum/post.php?reply=$post->id\n";
    }
    if (!$bare && $canunsubscribe) {
        $posttext .= "\n---------------------------------------------------------------------\n";
        $posttext .= get_string("unsubscribe", "partforum");
        $posttext .= ": $CFG->wwwroot/mod/partforum/subscribe.php?id=$partforum->id\n";
    }

    return $posttext;
}

/**
 * Builds and returns the body of the email notification in html format.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $partforum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @return string The email text in HTML format
 */
function partforum_make_mail_html($course, $cm, $partforum, $discussion, $post, $userfrom, $userto) {
    global $CFG;

    if ($userto->mailformat != 1) {  // Needs to be HTML
        return '';
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = partforum_user_can_post($partforum, $discussion, $userto);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $strpartforums = get_string('partforums', 'partforum');
    $canunsubscribe = ! partforum_is_forcesubscribed($partforum);

    $posthtml = '<head>';
    $posthtml .= '</head>';
    $posthtml .= "\n<body id=\"email\">\n\n";

    $posthtml .= '<div class="navbar">'.
    '<a target="_blank" href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">'.$course->shortname.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/index.php?id='.$course->id.'">'.$strpartforums.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$partforum->id.'">'.format_string($partforum->name,true).'</a>';
    if ($discussion->name == $partforum->name) {
        $posthtml .= '</div>';
    } else {
        $posthtml .= ' &raquo; <a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$discussion->id.'">'.
                     format_string($discussion->name,true).'</a></div>';
    }
    $posthtml .= partforum_make_mail_post($course, $cm, $partforum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

    if ($canunsubscribe) {
        $posthtml .= '<hr /><div class="mdl-align unsubscribelink">
                      <a href="'.$CFG->wwwroot.'/mod/partforum/subscribe.php?id='.$partforum->id.'">'.get_string('unsubscribe', 'partforum').'</a>&nbsp;
                      <a href="'.$CFG->wwwroot.'/mod/partforum/unsubscribeall.php">'.get_string('unsubscribeall', 'partforum').'</a></div>';
    }

    $posthtml .= '</body>';

    return $posthtml;
}


/**
 *
 * @param object $course
 * @param object $user
 * @param object $mod TODO this is not used in this function, refactor
 * @param object $partforum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function partforum_user_outline($course, $user, $mod, $partforum) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'partforum', $partforum->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = partforum_count_user_posts($partforum->id, $user->id);

    if ($count && $count->postcount > 0) {
        $result = new stdClass();
        $result->info = get_string("numposts", "partforum", $count->postcount);
        $result->time = $count->lastpost;
        if ($grade) {
            $result->info .= ', ' . get_string('grade') . ': ' . $grade->str_long_grade;
        }
        return $result;
    } else if ($grade) {
        $result = new stdClass();
        $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

        //datesubmitted == time created. dategraded == time modified or time overridden
        //if grade was last modified by the user themselves use date graded. Otherwise use date submitted
        //TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704
        if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
            $result->time = $grade->dategraded;
        } else {
            $result->time = $grade->datesubmitted;
        }

        return $result;
    }
    return NULL;
}


/**
 * @global object
 * @global object
 * @param object $coure
 * @param object $user
 * @param object $mod
 * @param object $partforum
 */
function partforum_user_complete($course, $user, $mod, $partforum) {
    global $CFG,$USER, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'partforum', $partforum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($posts = partforum_get_user_posts($partforum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = partforum_get_user_involved_discussions($partforum->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            partforum_print_post($post, $discussion, $partforum, $cm, $course, false, false, false);
        }
    } else {
        echo "<p>".get_string("noposts", "partforum")."</p>";
    }
}






/**
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function partforum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$partforums = get_all_instances_in_courses('partforum',$courses)) {
        return;
    }


    // get all partforum logs in ONE query (much better!)
    $params = array();
    $sql = "SELECT instance,cmid,l.course,COUNT(l.id) as count FROM {log} l "
        ." JOIN {course_modules} cm ON cm.id = cmid "
        ." WHERE (";
    foreach ($courses as $course) {
        $sql .= '(l.course = ? AND l.time > ?) OR ';
        $params[] = $course->id;
        $params[] = $course->lastaccess;
    }
    $sql = substr($sql,0,-3); // take off the last OR

    $sql .= ") AND l.module = 'partforum' AND action = 'add post' "
        ." AND userid != ? GROUP BY cmid,l.course,instance";

    $params[] = $USER->id;

    if (!$new = $DB->get_records_sql($sql, $params)) {
        $new = array(); // avoid warnings
    }

    // also get all partforum tracking stuff ONCE.
    $trackingpartforums = array();
    foreach ($partforums as $partforum) {
        if (partforum_tp_can_track_partforums($partforum)) {
            $trackingpartforums[$partforum->id] = $partforum;
        }
    }

    if (count($trackingpartforums) > 0) {
        $cutoffdate = isset($CFG->partforum_oldpostdays) ? (time() - ($CFG->partforum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.partforum,d.course,COUNT(p.id) AS count '.
            ' FROM {partforum_posts} p '.
            ' JOIN {partforum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {partforum_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackingpartforums as $track) {
            $sql .= '(d.partforum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                $groupid = groups_get_all_groups($track->course, $USER->id);
                if (is_array($groupid)) {
                    $groupid = array_shift(array_keys($groupid));
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL GROUP BY d.partforum,d.course';
        $params[] = $cutoffdate;

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($new)) {
        return;
    }

    $strpartforum = get_string('modulename','partforum');
    $strnumunread = get_string('overviewnumunread','partforum');
    $strnumpostssince = get_string('overviewnumpostssince','partforum');

    foreach ($partforums as $partforum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($partforum->id, $new) && !empty($new[$partforum->id])) {
            $count = $new[$partforum->id]->count;
        }
        if (array_key_exists($partforum->id,$unread)) {
            $thisunread = $unread[$partforum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview partforum"><div class="name">'.$strpartforum.': <a title="'.$strpartforum.'" href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$partforum->id.'">'.
                $partforum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= $count.' '.$strnumpostssince."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.$thisunread .' '.$strnumunread.'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($partforum->course,$htmlarray)) {
                $htmlarray[$partforum->course] = array();
            }
            if (!array_key_exists('partforum',$htmlarray[$partforum->course])) {
                $htmlarray[$partforum->course]['partforum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$partforum->course]['partforum'] .= $str;
        }
    }
}

/**
 * Given a course and a date, prints a summary of all the new
 * messages posted in the course since that date
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $course
 * @param bool $viewfullnames capability
 * @param int $timestart
 * @return bool success
 */
function partforum_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    // do not use log table if possible, it may be huge and is expensive to join with other tables

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS partforumtype, d.partforum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture
                                         FROM {partforum_posts} p
                                              JOIN {partforum_discussions} d ON d.id = p.discussion
                                              JOIN {partforum} f             ON f.id = d.partforum
                                              JOIN {user} u              ON u.id = p.userid
                                        WHERE p.created > ? AND f.course = ?
                                     ORDER BY p.id ASC", array($timestart, $course->id))) { // order by initial posting date
         return false;
    }

    $modinfo =& get_fast_modinfo($course);

    $groupmodes = array();
    $cms    = array();

    $strftimerecent = get_string('strftimerecent');

    $printposts = array();
    foreach ($posts as $post) {
        if (!isset($modinfo->instances['partforum'][$post->partforum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['partforum'][$post->partforum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = context_module::instance($cm->id);

        if (!has_capability('mod/partforum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->partforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!has_capability('mod/partforum:viewhiddentimedposts', $context)) {
                continue;
            }
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $context)) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }
    unset($posts);

    if (!$printposts) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newpartforumposts', 'partforum').':', 3);
    echo "\n<ul class='unlist'>\n";

    foreach ($printposts as $post) {
        $subjectclass = empty($post->parent) ? ' bold' : '';
        
        echo '<li><div class="head">'.
               '<div class="date">'.userdate($post->modified, $strftimerecent).'</div>'.
               '<div class="name">'.fullname($post, $viewfullnames).'</div>'.
             '</div>';
        echo '<div class="info'.$subjectclass.'">';
        if (empty($post->parent)) {
            echo '"<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'">';
        } else {
            echo '"<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'&amp;parent='.$post->parent.'#p'.$post->id.'">';
        }
        $post->subject = break_up_long_words(format_string($post->subject, true));
        echo $post->subject;
        echo "</a>\"</div></li>\n";
    }

    echo "</ul>\n";

    return true;
}

/**
 * Return grade for given user or all users.
 *
 * @global object
 * @global object
 * @param object $partforum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function partforum_get_user_grades($partforum, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_partforum';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'partforum';
    $ratingoptions->moduleid   = $partforum->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $partforum->assessed;
    $ratingoptions->scaleid = $partforum->scale;
    $ratingoptions->itemtable = 'partforum_posts';
    $ratingoptions->itemtableusercolumn = 'userid';

    $rm = new rating_manager();
    return $rm->get_user_grades($ratingoptions);
}

/**
 * Update activity grades
 *
 * @global object
 * @global object
 * @param object $partforum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function partforum_update_grades($partforum, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$partforum->assessed) {
        partforum_grade_item_update($partforum);

    } else if ($grades = partforum_get_user_grades($partforum, $userid)) {
        partforum_grade_item_update($partforum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        partforum_grade_item_update($partforum, $grade);

    } else {
        partforum_grade_item_update($partforum);
    }
}

/**
 * Update all grades in gradebook.
 * @global object
 */
function partforum_upgrade_grades() {
    global $DB;

    $sql = "SELECT COUNT('x')
              FROM {partforum} f, {course_modules} cm, {modules} m
             WHERE m.name='partforum' AND m.id=cm.module AND cm.instance=f.id";
    $count = $DB->count_records_sql($sql);

    $sql = "SELECT f.*, cm.idnumber AS cmidnumber, f.course AS courseid
              FROM {partforum} f, {course_modules} cm, {modules} m
             WHERE m.name='partforum' AND m.id=cm.module AND cm.instance=f.id";
    $rs = $DB->get_recordset_sql($sql);
    if ($rs->valid()) {
        $pbar = new progress_bar('partforumupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $partforum) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            partforum_update_grades($partforum, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create/update grade item for given partforum
 *
 * @global object
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param object $partforum object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function partforum_grade_item_update($partforum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }
    
    if($partforum->cmidnumber != ''){
        $params = array('itemname'=>$partforum->name, 'idnumber'=>$partforum->cmidnumber);
	}else{
	    $params = array('itemname'=>$partforum->name);
	}
    
    if (!$partforum->assessed or $partforum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($partforum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $partforum->scale;
        $params['grademin']  = 0;

    } else if ($partforum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$partforum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/partforum', $partforum->course, 'mod', 'partforum', $partforum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given partforum
 *
 * @global object
 * @param object $partforum object
 * @return object grade_item
 */
function partforum_grade_item_delete($partforum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/partforum', $partforum->course, 'mod', 'partforum', $partforum->id, 0, NULL, array('deleted'=>1));
}


/**
 * Returns the users with data in one partforum
 * (users with records in partforum_subscriptions, partforum_posts, students)
 *
 * @todo: deprecated - to be deleted in 2.2
 *
 * @param int $partforumid
 * @return mixed array or false if none
 */
function partforum_get_participants($partforumid) {

    global $CFG, $DB;

    $params = array('partforumid' => $partforumid);

    //Get students from partforum_subscriptions
    $sql = "SELECT DISTINCT u.id, u.id
              FROM {user} u,
                   {partforum_subscriptions} s
             WHERE s.partforum = :partforumid AND
                   u.id = s.userid";
    $st_subscriptions = $DB->get_records_sql($sql, $params);

    //Get students from partforum_posts
    $sql = "SELECT DISTINCT u.id, u.id
              FROM {user} u,
                   {partforum_discussions} d,
                   {partforum_posts} p
              WHERE d.partforum = :partforumid AND
                    p.discussion = d.id AND
                    u.id = p.userid";
    $st_posts = $DB->get_records_sql($sql, $params);

    //Get students from the ratings table
    $sql = "SELECT DISTINCT r.userid, r.userid AS id
              FROM {partforum_discussions} d
              JOIN {partforum_posts} p ON p.discussion = d.id
              JOIN {rating} r on r.itemid = p.id
             WHERE d.partforum = :partforumid AND
                   r.component = 'mod_partforum' AND
                   r.ratingarea = 'post'";
    $st_ratings = $DB->get_records_sql($sql, $params);

    //Add st_posts to st_subscriptions
    if ($st_posts) {
        foreach ($st_posts as $st_post) {
            $st_subscriptions[$st_post->id] = $st_post;
        }
    }
    //Add st_ratings to st_subscriptions
    if ($st_ratings) {
        foreach ($st_ratings as $st_rating) {
            $st_subscriptions[$st_rating->id] = $st_rating;
        }
    }
    //Return st_subscriptions array (it contains an array of unique users)
    return ($st_subscriptions);
}

/**
 * This function returns if a scale is being used by one partforum
 *
 * @global object
 * @param int $partforumid
 * @param int $scaleid negative number
 * @return bool
 */
function partforum_scale_used ($partforumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("partforum",array("id" => "$partforumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of partforum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any partforum
 */
function partforum_scale_used_anywhere($scaleid) {
    global $DB;
    if ($scaleid and $DB->record_exists('partforum', array('scale' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

// SQL FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Gets a post with all info ready for partforum_print_post
 * Most of these joins are just to get the partforum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function partforum_get_post_full($postid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*, d.partforum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                             FROM {partforum_posts} p
                                  JOIN {partforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets posts with all info ready for partforum_print_post
 * We pass partforumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @return mixed array of posts or false
 */
function partforum_get_discussion_posts($discussion, $sort, $partforumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $partforumid AS partforum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {partforum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.discussion = ?
                               AND p.parent > 0 $sort", array($discussion));
}

/**
 * Gets all posts in discussion including top parent.
 *
 * @global object
 * @global object
 * @global object
 * @param int $discussionid
 * @param string $sort
 * @param bool $tracking does user track the partforum?
 * @return array of posts
 */
function partforum_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $now = time();
        $cutoffdate = $now - ($CFG->partforum_oldpostdays * 24 * 3600);
        $tr_sel  = ", fr.id AS postread";
        $tr_join = "LEFT JOIN {partforum_read} fr ON (fr.postid = p.id AND fr.userid = ?)";
        $params[] = $USER->id;
    }

    $params[] = $discussionid;
    if (!$posts = $DB->get_records_sql("SELECT p.*, u.firstname, u.lastname, u.email, u.picture, u.imagealt $tr_sel
                                     FROM {partforum_posts} p
                                          LEFT JOIN {user} u ON p.userid = u.id
                                          $tr_join
                                    WHERE p.discussion = ?
                                 ORDER BY $sort", $params)) {
        return array();
    }

    foreach ($posts as $pid=>$p) {
        if ($tracking) {
            if (partforum_tp_is_post_old($p)) {
                 $posts[$pid]->postread = true;
            }
        }
        if (!$p->parent) {
            continue;
        }
        if (!isset($posts[$p->parent])) {
            continue; // parent does not exist??
        }
        if (!isset($posts[$p->parent]->children)) {
            $posts[$p->parent]->children = array();
        }
        $posts[$p->parent]->children[$pid] =& $posts[$pid];
    }

    return $posts;
}

/**
 * Gets posts with all info ready for partforum_print_post
 * We pass partforumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @param int $parent
 * @param int $partforumid
 * @return array
 */
function partforum_get_child_posts($parent, $partforumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $partforumid AS partforum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {partforum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.parent = ?
                          ORDER BY p.created ASC", array($parent));
}

/**
 * An array of partforum objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for partforums throughout the whole site.
 * @return array of partforum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function partforum_get_readable_partforums($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$partforummod = $DB->get_record('modules', array('name' => 'partforum'))) {
        print_error('notinstalled', 'partforum');
    }

    if ($courseid) {
        $courses = $DB->get_records('course', array('id' => $courseid));
    } else {
        // If no course is specified, then the user can see SITE + his courses.
        $courses1 = $DB->get_records('course', array('id' => SITEID));
        $courses2 = enrol_get_users_courses($userid, true, array('modinfo'));
        $courses = array_merge($courses1, $courses2);
    }
    if (!$courses) {
        return array();
    }

    $readablepartforums = array();

    foreach ($courses as $course) {

        $modinfo =& get_fast_modinfo($course);
        if (is_null($modinfo->groups)) {
            $modinfo->groups = groups_get_user_groups($course->id, $userid);
        }

        if (empty($modinfo->instances['partforum'])) {
            // hmm, no partforums?
            continue;
        }

        $coursepartforums = $DB->get_records('partforum', array('course' => $course->id));

        foreach ($modinfo->instances['partforum'] as $partforumid => $cm) {
            if (!$cm->uservisible or !isset($coursepartforums[$partforumid])) {
                continue;
            }
            $context = context_module::instance($cm->id);
            $partforum = $coursepartforums[$partforumid];
            $partforum->context = $context;
            $partforum->cm = $cm;

            if (!has_capability('mod/partforum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {
                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
                }
                if (isset($modinfo->groups[$cm->groupingid])) {
                    $partforum->onlygroups = $modinfo->groups[$cm->groupingid];
                    $partforum->onlygroups[] = -1;
                } else {
                    $partforum->onlygroups = array(-1);
                }
            }

        /// hidden timed discussions
            $partforum->viewhiddentimedposts = true;
            if (!empty($CFG->partforum_enabletimedposts)) {
                if (!has_capability('mod/partforum:viewhiddentimedposts', $context)) {
                    $partforum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($partforum->type == 'qanda'
                    && !has_capability('mod/partforum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda partforum.
                $partforum->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this partforum.
                if ($discussionspostedin = partforum_discussions_user_has_posted_in($partforum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $partforum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readablepartforums[$partforum->id] = $partforum;
        }

        unset($modinfo);

    } // End foreach $courses

    return $readablepartforums;
}

/**
 * Returns a list of posts found using an array of search terms.
 *
 * @global object
 * @global object
 * @global object
 * @param array $searchterms array of search terms, e.g. word +word -word
 * @param int $courseid if 0, we search through the whole site
 * @param int $limitfrom
 * @param int $limitnum
 * @param int &$totalcount
 * @param string $extrasql
 * @return array|bool Array of posts found or false
 */
function partforum_search_posts($searchterms, $courseid=0, $limitfrom=0, $limitnum=50,
                            &$totalcount, $extrasql='') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir.'/searchlib.php');

    $partforums = partforum_get_readable_partforums($USER->id, $courseid);

    if (count($partforums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($partforums as $partforumid => $partforum) {
        $select = array();

        if (!$partforum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$partforumid} OR (d.timestart < :timestart{$partforumid} AND (d.timeend = 0 OR d.timeend > :timeend{$partforumid})))";
            $params = array_merge($params, array('userid'.$partforumid=>$USER->id, 'timestart'.$partforumid=>$now, 'timeend'.$partforumid=>$now));
        }

        $cm = $partforum->cm;
        $context = $partforum->context;

        if ($partforum->type == 'qanda'
            && !has_capability('mod/partforum:viewqandawithoutposting', $context)) {
            if (!empty($partforum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($partforum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$partforumid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($partforum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($partforum->onlygroups, SQL_PARAMS_NAMED, 'grps'.$partforumid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.partforum = :partforum{$partforumid} AND $selects)";
            $params['partforum'.$partforumid] = $partforumid;
        } else {
            $fullaccess[] = $partforumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.partforum $fullid_sql)";
    }

    $selectdiscussion = "(".implode(" OR ", $where).")";

    $messagesearch = '';
    $searchstring = '';

    // Need to concat these back together for parser to work.
    foreach($searchterms as $searchterm){
        if ($searchstring != '') {
            $searchstring .= ' ';
        }
        $searchstring .= $searchterm;
    }

    // We need to allow quoted strings for the search. The quotes *should* be stripped
    // by the parser, but this should be examined carefully for security implications.
    $searchstring = str_replace("\\\"","\"",$searchstring);
    $parser = new search_parser();
    $lexer = new search_lexer($parser);

    if ($lexer->parse($searchstring)) {
        $parsearray = $parser->get_parsed_array();
    // Experimental feature under 1.8! MDL-8830
    // Use alternative text searches if defined
    // This feature only works under mysql until properly implemented for other DBs
    // Requires manual creation of text index for partforum_posts before enabling it:
    // CREATE FULLTEXT INDEX foru_post_tix ON [prefix]partforum_posts (subject, message)
    // Experimental feature under 1.8! MDL-8830
        if (!empty($CFG->partforum_usetextsearches)) {
            list($messagesearch, $msparams) = search_generate_text_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.partforum');
        } else {
            list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.partforum');
        }
        $params = array_merge($params, $msparams);
    }

    $fromsql = "{partforum_posts} p,
                  {partforum_discussions} d,
                  {user} u";

    $selectsql = " $messagesearch
               AND p.discussion = d.id
               AND p.userid = u.id
               AND $selectdiscussion
                   $extrasql";

    $countsql = "SELECT COUNT(*)
                   FROM $fromsql
                  WHERE $selectsql";

    $searchsql = "SELECT p.*,
                         d.partforum,
                         u.firstname,
                         u.lastname,
                         u.email,
                         u.picture,
                         u.imagealt,
                         u.email
                    FROM $fromsql
                   WHERE $selectsql
                ORDER BY p.modified DESC";

    $totalcount = $DB->count_records_sql($countsql, $params);

    return $DB->get_records_sql($searchsql, $params, $limitfrom, $limitnum);
}

/**
 * Returns a list of ratings for a particular post - sorted.
 *
 * TODO: Check if this function is actually used anywhere.
 * Up until the fix for MDL-27471 this function wasn't even returning.
 *
 * @param stdClass $context
 * @param int $postid
 * @param string $sort
 * @return array Array of ratings or false
 */
function partforum_get_ratings($context, $postid, $sort = "u.firstname ASC") {
    $options = new stdClass;
    $options->context = $context;
    $options->component = 'mod_partforum';
    $options->ratingarea = 'post';
    $options->itemid = $postid;
    $options->sort = "ORDER BY $sort";

    $rm = new rating_manager();
    return $rm->get_all_ratings_for_item($options);
}

/**
 * Returns a list of all new posts that have not been mailed yet
 *
 * @param int $starttime posts created after this time
 * @param int $endtime posts created before this
 * @param int $now used for timed discussions only
 * @return array
 */
function partforum_get_unmailed_posts($starttime, $endtime, $now=null) {
    global $CFG, $DB;

    $params = array($starttime, $endtime);
    if (!empty($CFG->partforum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.partforum
                              FROM {partforum_posts} p
                                   JOIN {partforum_discussions} d ON d.id = p.discussion
                             WHERE p.mailed = 0
                                   AND p.created >= ?
                                   AND (p.created < ? OR p.mailnow = 1)
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Marks posts before a certain time as being mailed already
 *
 * @global object
 * @global object
 * @param int $endtime
 * @param int $now Defaults to time()
 * @return bool
 */
function partforum_mark_old_posts_as_mailed($endtime, $now=null) {
    global $CFG, $DB;
    if (empty($now)) {
        $now = time();
    }

    if (empty($CFG->partforum_enabletimedposts)) {
        return $DB->execute("UPDATE {partforum_posts}
                               SET mailed = '1'
                             WHERE (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($endtime));

    } else {
        return $DB->execute("UPDATE {partforum_posts}
                               SET mailed = '1'
                             WHERE discussion NOT IN (SELECT d.id
                                                        FROM {partforum_discussions} d
                                                       WHERE d.timestart > ?)
                                   AND (created < ? OR mailnow = 1)
                                   AND mailed = 0", array($now, $endtime));
    }
}

/**
 * Get all the posts for a user in a partforum suitable for partforum_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function partforum_get_user_posts($partforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($partforumid, $userid);

    if (!empty($CFG->partforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $partforumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT p.*, d.partforum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {partforum} f
                                   JOIN {partforum_discussions} d ON d.partforum = f.id
                                   JOIN {partforum_posts} p       ON p.discussion = d.id
                                   JOIN {user} u              ON u.id = p.userid
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql
                          ORDER BY p.modified ASC", $params);
}

/**
 * Get all the discussions user participated in
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @param int $partforumid
 * @param int $userid
 * @return array Array or false
 */
function partforum_get_user_involved_discussions($partforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($partforumid, $userid);
    if (!empty($CFG->partforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $partforumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {partforum} f
                                   JOIN {partforum_discussions} d ON d.partforum = f.id
                                   JOIN {partforum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a partforum suitable for partforum_print_post
 *
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $userid
 * @return array of counts or false
 */
function partforum_count_user_posts($partforumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($partforumid, $userid);
    if (!empty($CFG->partforum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $partforumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , context_module::instance($cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {partforum} f
                                  JOIN {partforum_discussions} d ON d.partforum = f.id
                                  JOIN {partforum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the partforum post details for it.
 *
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function partforum_get_post_from_log($log) {
    global $CFG, $DB;

    if ($log->action == "add post") {

        return $DB->get_record_sql("SELECT p.*, f.type AS partforumtype, d.partforum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {partforum_discussions} d,
                                      {partforum_posts} p,
                                      {partforum} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.partforum", array($log->info));


    } else if ($log->action == "add discussion") {

        return $DB->get_record_sql("SELECT p.*, f.type AS partforumtype, d.partforum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {partforum_discussions} d,
                                      {partforum_posts} p,
                                      {partforum} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.partforum", array($log->info));
    }
    return NULL;
}

/**
 * Given a discussion id, return the first post from the discussion
 *
 * @global object
 * @global object
 * @param int $dicsussionid
 * @return array
 */
function partforum_get_firstpost_from_discussion($discussionid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*
                             FROM {partforum_discussions} d,
                                  {partforum_posts} p
                            WHERE d.id = ?
                              AND d.firstpost = p.id ", array($discussionid));
}

/**
 * Returns an array of counts of replies to each discussion
 *
 * @global object
 * @global object
 * @param int $partforumid
 * @param string $partforumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function partforum_count_discussion_replies($partforumid, $partforumsort="", $limit=-1, $page=-1, $perpage=0) {
    global $CFG, $DB;

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    if ($partforumsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $partforumsort";
        $groupby = ", ".strtolower($partforumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $partforumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.partforum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($partforumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d ON p.discussion = d.id
                 WHERE d.partforum = ?
              GROUP BY p.discussion $groupby
              $orderby";
        return $DB->get_records_sql("SELECT * FROM ($sql) sq", array($partforumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $partforum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function partforum_count_discussions($partforum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($CFG->partforum_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {partforum} f
                       JOIN {partforum_discussions} d ON d.partforum = f.id
                 WHERE f.course = ?
                       $timedsql
              GROUP BY f.id";

        if ($counts = $DB->get_records_sql($sql, $params)) {
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }
            $cache[$course->id] = $counts;
        } else {
            $cache[$course->id] = array();
        }
    }

    if (empty($cache[$course->id][$partforum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$partforum->id];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $cache[$course->id][$partforum->id];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo =& get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    if (array_key_exists($cm->groupingid, $modinfo->groups)) {
        $mygroups = $modinfo->groups[$cm->groupingid];
    } else {
        $mygroups = false; // Will be set below
    }

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list($mygroups_sql, $params) = $DB->get_in_or_equal($mygroups);
    $params[] = $partforum->id;

    if (!empty($CFG->partforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {partforum_discussions} d
             WHERE d.groupid $mygroups_sql AND d.partforum = ?
                   $timedsql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * How many posts by other users are unrated by a given user in the given discussion?
 *
 * TODO: Is this function still used anywhere?
 *
 * @param int $discussionid
 * @param int $userid
 * @return mixed
 */
function partforum_count_unrated_posts($discussionid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT COUNT(*) as num
              FROM {partforum_posts}
             WHERE parent > 0
               AND discussion = :discussionid
               AND userid <> :userid";
    $params = array('discussionid' => $discussionid, 'userid' => $userid);
    $posts = $DB->get_record_sql($sql, $params);
    if ($posts) {
        $sql = "SELECT count(*) as num
                  FROM {partforum_posts} p,
                       {rating} r
                 WHERE p.discussion = :discussionid AND
                       p.id = r.itemid AND
                       r.userid = userid AND
                       r.component = 'mod_partforum' AND
                       r.ratingarea = 'post'";
        $rated = $DB->get_record_sql($sql, $params);
        if ($rated) {
            if ($posts->num > $rated->num) {
                return $posts->num - $rated->num;
            } else {
                return 0;    // Just in case there was a counting error
            }
        } else {
            return $posts->num;
        }
    } else {
        return 0;
    }
}

/**
 * Get all discussions in a partforum
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $partforumsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @return array
 */
function partforum_get_discussions($cm, $partforumsort="d.timemodified DESC", $fullpost=true, $unused=-1, $limit=-1, $userlastmodified=false, $page=-1, $perpage=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!has_capability('mod/partforum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->partforum_enabletimedposts)) { /// Users must fulfill timed posts

        if (!has_capability('mod/partforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    if ($limit > 0) {
        $limitfrom = 0;
        $limitnum  = $limit;
    } else if ($page != -1) {
        $limitfrom = $page*$perpage;
        $limitnum  = $perpage;
    } else {
        $limitfrom = 0;
        $limitnum  = 0;
    }

    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        if (empty($modcontext)) {
            $modcontext = context_module::instance($cm->id);
        }

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }


    if (empty($partforumsort)) {
        $partforumsort = "d.timemodified DESC";
    }
    if (empty($fullpost)) {
        $postdata = "p.id,p.subject,p.modified,p.discussion,p.userid";
    } else {
        $postdata = "p.*";
    }

    if (empty($userlastmodified)) {  // We don't need to know this
        $umfields = "";
        $umtable  = "";
    } else {
        $umfields = ", um.firstname AS umfirstname, um.lastname AS umlastname";
        $umtable  = " LEFT JOIN {user} um ON (d.usermodified = um.id)";
    }

    $sql = "SELECT $postdata, d.name, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend,
                   u.firstname, u.lastname, u.email, u.picture, u.imagealt $umfields
              FROM {partforum_discussions} d
                   JOIN {partforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
                   $umtable
             WHERE d.partforum = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $partforumsort";
    return $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
}

/**
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function partforum_get_discussions_unread($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $cutoffdate = $now - ($CFG->partforum_oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //separate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :currentgroup OR d.groupid = -1)";
                $params['currentgroup'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    if (!empty($CFG->partforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < :now1 AND (d.timeend = 0 OR d.timeend > :now2)";
        $params['now1'] = $now;
        $params['now2'] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT d.id, COUNT(p.id) AS unread
              FROM {partforum_discussions} d
                   JOIN {partforum_posts} p     ON p.discussion = d.id
                   LEFT JOIN {partforum_read} r ON (r.postid = p.id AND r.userid = $USER->id)
             WHERE d.partforum = {$cm->instance}
                   AND p.modified >= :cutoffdate AND r.id is NULL
                   $groupselect
                   $timedsql
          GROUP BY d.id";
    $params['cutoffdate'] = $cutoffdate;

    if ($unreads = $DB->get_records_sql($sql, $params)) {
        foreach ($unreads as $unread) {
            $unreads[$unread->id] = $unread->unread;
        }
        return $unreads;
    } else {
        return array();
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @uses CONEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @return array
 */
function partforum_get_discussions_count($cm) {
    global $CFG, $DB, $USER;

    $now = round(time(), -2);
    $params = array($cm->instance);
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = context_module::instance($cm->id);

        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "";
            }

        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = ? OR d.groupid = -1)";
                $params[] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    } else {
        $groupselect = "";
    }

    $cutoffdate = $now - ($CFG->partforum_oldpostdays*24*60*60);

    $timelimit = "";

    if (!empty($CFG->partforum_enabletimedposts)) {

        $modcontext = context_module::instance($cm->id);

        if (!has_capability('mod/partforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = ?";
                $params[] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    $sql = "SELECT COUNT(d.id)
              FROM {partforum_discussions} d
                   JOIN {partforum_posts} p ON p.discussion = d.id
             WHERE d.partforum = ? AND p.parent = 0
                   $groupselect $timelimit";

    return $DB->get_field_sql($sql, $params);
}


/**
 * Get all discussions started by a particular user in a course (or group)
 * This function no longer used ...
 *
 * @todo Remove this function if no longer used
 * @global object
 * @global object
 * @param int $courseid
 * @param int $userid
 * @param int $groupid
 * @return array
 */
function partforum_get_user_discussions($courseid, $userid, $groupid=0) {
    global $CFG, $DB;
    $params = array($courseid, $userid);
    if ($groupid) {
        $groupselect = " AND d.groupid = ? ";
        $params[] = $groupid;
    } else  {
        $groupselect = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.groupid, u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                                   f.type as partforumtype, f.name as partforumname, f.id as partforumid
                              FROM {partforum_discussions} d,
                                   {partforum_posts} p,
                                   {user} u,
                                   {partforum} f
                             WHERE d.course = ?
                               AND p.discussion = d.id
                               AND p.parent = 0
                               AND p.userid = u.id
                               AND u.id = ?
                               AND d.partforum = f.id $groupselect
                          ORDER BY p.created DESC", $params);
}

/**
 * Get the list of potential subscribers to a partforum.
 *
 * @param object $partforumcontext the partforum context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function partforum_get_potential_subscribers($partforumcontext, $groupid, $fields, $sort) {
    global $DB;

    // only active enrolled users or everybody on the frontpage with this capability
    list($esql, $params) = get_enrolled_sql($partforumcontext, 'mod/partforum:initialsubscriptions', $groupid, true);

    $sql = "SELECT $fields
              FROM {user} u
              JOIN ($esql) je ON je.id = u.id";
    if ($sort) {
        $sql = "$sql ORDER BY $sort";
    } else {
        $sql = "$sql ORDER BY u.lastname ASC, u.firstname ASC";
    }

    return $DB->get_records_sql($sql, $params);
}

/**
 * Returns list of user objects that are subscribed to this partforum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param partforum $partforum the partforum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the partforum context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function partforum_subscribed_users($course, $partforum, $groupid=0, $context = null, $fields = null) {
    global $CFG, $DB;

    if (empty($fields)) {
        $fields ="u.id,
                  u.username,
                  u.firstname,
                  u.lastname,
                  u.maildisplay,
                  u.mailformat,
                  u.maildigest,
                  u.imagealt,
                  u.email,
                  u.city,
                  u.country,
                  u.lastaccess,
                  u.lastlogin,
                  u.picture,
                  u.timezone,
                  u.theme,
                  u.lang,
                  u.trackpartforums,
                  u.mnethostid";
    }

    if (empty($context)) {
        $cm = get_coursemodule_from_instance('partforum', $partforum->id, $course->id);
        $context = context_module::instance($cm->id);
    }

    if (partforum_is_forcesubscribed($partforum)) {
        $results = partforum_get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

    } else {
        // only active enrolled users or everybody on the frontpage
        list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
        $params['partforumid'] = $partforum->id;
        $results = $DB->get_records_sql("SELECT $fields
                                           FROM {user} u
                                           JOIN ($esql) je ON je.id = u.id
                                           JOIN {partforum_subscriptions} s ON s.userid = u.id
                                          WHERE s.partforum = :partforumid
                                       ORDER BY u.email ASC", $params);
    }

    // Guest user should never be subscribed to a partforum.
    unset($results[$CFG->siteguest]);

    return $results;
}



// OTHER FUNCTIONS ///////////////////////////////////////////////////////////


/**
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type
 */
function partforum_get_course_partforum($courseid, $type) {
// How to set up special 1-per-course partforums
    global $CFG, $DB, $OUTPUT;

    if ($partforums = $DB->get_records_select("partforum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($partforums as $partforum) {
            return $partforum;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $partforum->course = $courseid;
    $partforum->type = "$type";
    switch ($partforum->type) {
        case "news":
            $partforum->name  = get_string("namenews", "partforum");
            $partforum->intro = get_string("intronews", "partforum");
            $partforum->forcesubscribe = PARTFORUM_FORCESUBSCRIBE;
            $partforum->assessed = 0;
            if ($courseid == SITEID) {
                $partforum->name  = get_string("sitenews");
                $partforum->forcesubscribe = 0;
            }
            break;
        case "social":
            $partforum->name  = get_string("namesocial", "partforum");
            $partforum->intro = get_string("introsocial", "partforum");
            $partforum->assessed = 0;
            $partforum->forcesubscribe = 0;
            break;
        case "blog":
            $partforum->name = get_string('blogpartforum', 'partforum');
            $partforum->intro = get_string('introblog', 'partforum');
            $partforum->assessed = 0;
            $partforum->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That partforum type doesn't exist!");
            return false;
            break;
    }

    $partforum->timemodified = time();
    $partforum->id = $DB->insert_record("partforum", $partforum);

    if (! $module = $DB->get_record("modules", array("name" => "partforum"))) {
        echo $OUTPUT->notification("Could not find partforum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $partforum->id;
    $mod->section = 0;
    if (! $mod->coursemodule = add_course_module($mod) ) {   // assumes course/lib.php is loaded
        echo $OUTPUT->notification("Could not add a new course module to the course '" . $courseid . "'");
        return false;
    }
    if (! $sectionid = add_mod_to_section($mod) ) {   // assumes course/lib.php is loaded
        echo $OUTPUT->notification("Could not add the new course module to that section");
        return false;
    }
    $DB->set_field("course_modules", "section", $sectionid, array("id" => $mod->coursemodule));

    include_once("$CFG->dirroot/course/lib.php");
    rebuild_course_cache($courseid);

    return $DB->get_record("partforum", array("id" => "$partforum->id"));
}


/**
 * Given the data about a posting, builds up the HTML to display it and
 * returns the HTML in a string.  This is designed for sending via HTML email.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $partforum
 * @param object $discussion
 * @param object $post
 * @param object $userform
 * @param object $userto
 * @param bool $ownpost
 * @param bool $reply
 * @param bool $link
 * @param bool $rate
 * @param string $footer
 * @return string
 */
function partforum_make_mail_post($course, $cm, $partforum, $discussion, $post, $userfrom, $userto,
                              $ownpost=false, $reply=false, $link=false, $rate=false, $footer="") {

    global $CFG, $OUTPUT;

    $modcontext = context_module::instance($cm->id);

    if (!isset($userto->viewfullnames[$partforum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$partforum->id];
    }

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_partforum', 'post', $post->id);

    // format the post body
    $options = new stdClass();
    $options->para = true;
    $formattedtext = format_text($post->message, $post->messageformat, $options, $course->id);

    $output = '<table border="0" cellpadding="3" cellspacing="0" class="partforumpost">';

    $output .= '<tr class="header"><td width="35" valign="top" class="picture left">';
    $output .= $OUTPUT->user_picture($userfrom, array('courseid'=>$course->id));
    $output .= '</td>';

    if ($post->parent) {
        $output .= '<td class="topic">';
    } else {
        $output .= '<td class="topic starter">';
    }
    $output .= '<div class="subject">'.format_string($post->subject).'</div>';

    $fullname = fullname($userfrom, $viewfullnames);
    $by = new stdClass();
    $by->name = '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$userfrom->id.'&amp;course='.$course->id.'">'.$fullname.'</a>';
    $by->date = userdate($post->modified, '', $userto->timezone);
    $output .= '<div class="author">'.get_string('bynameondate', 'partforum', $by).'</div>';

    $output .= '</td></tr>';

    $output .= '<tr><td class="left side" valign="top">';

    if (isset($userfrom->groups)) {
        $groups = $userfrom->groups[$partforum->id];
    } else {
        $groups = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
    }

    if ($groups) {
        $output .= print_group_picture($groups, $course->id, false, true, true);
    } else {
        $output .= '&nbsp;';
    }

    $output .= '</td><td class="content">';

    $attachments = partforum_print_attachments($post, $cm, 'html');
    if ($attachments !== '') {
        $output .= '<div class="attachments">';
        $output .= $attachments;
        $output .= '</div>';
    }

    $output .= $formattedtext;

// Commands
    $commands = array();

    if ($post->parent) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.
                      $post->discussion.'&amp;parent='.$post->parent.'">'.get_string('parent', 'partforum').'</a>';
    }

    if ($reply) {
        $commands[] = '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/post.php?reply='.$post->id.'">'.
                      get_string('reply', 'partforum').'</a>';
    }

    $output .= '<div class="commands">';
    $output .= implode(' | ', $commands);
    $output .= '</div>';

// Context link to post if required
    if ($link) {
        $output .= '<div class="link">';
        $output .= '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'#p'.$post->id.'">'.
                     get_string('postincontext', 'partforum').'</a>';
        $output .= '</div>';
    }

    if ($footer) {
        $output .= '<div class="footer">'.$footer.'</div>';
    }
    $output .= '</td></tr></table>'."\n\n";

    return $output;
}

/**
 * Print a partforum post
 *
 * @global object
 * @global object
 * @uses PARTFORUM_MODE_THREADED
 * @uses PORTFOLIO_FORMAT_PLAINHTML
 * @uses PORTFOLIO_FORMAT_FILE
 * @uses PORTFOLIO_FORMAT_RICHHTML
 * @uses PORTFOLIO_ADD_TEXT_LINK
 * @uses CONTEXT_MODULE
 * @param object $post The post to print.
 * @param object $discussion
 * @param object $partforum
 * @param object $cm
 * @param object $course
 * @param boolean $ownpost Whether this post belongs to the current user.
 * @param boolean $reply Whether to print a 'reply' link at the bottom of the message.
 * @param boolean $link Just print a shortened version of the post as a link to the full post.
 * @param string $footer Extra stuff to print after the message.
 * @param string $highlight Space-separated list of terms to highlight.
 * @param int $post_read true, false or -99. If we already know whether this user
 *          has read this post, pass that in, otherwise, pass in -99, and this
 *          function will work it out.
 * @param boolean $dummyifcantsee When partforum_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 * @return void
 */
function partforum_print_post($post, $discussion, $partforum, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
    global $USER, $CFG, $OUTPUT, $DB;

    require_once($CFG->libdir . '/filelib.php');


    // String cache
    static $str;

    $modcontext = context_module::instance($cm->id);


    $post->course = $course->id;
    $post->partforum  = $partforum->id;
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_partforum', 'post', $post->id);

    // caching
    if (!isset($cm->cache)) {
        $cm->cache = new stdClass;
    }

    if (!isset($cm->cache->caps)) {
        $cm->cache->caps = array();
        $cm->cache->caps['mod/partforum:viewdiscussion']   = has_capability('mod/partforum:viewdiscussion', $modcontext);
        $cm->cache->caps['moodle/site:viewfullnames']  = has_capability('moodle/site:viewfullnames', $modcontext);
        $cm->cache->caps['mod/partforum:editanypost']      = has_capability('mod/partforum:editanypost', $modcontext);
        $cm->cache->caps['mod/partforum:splitdiscussions'] = has_capability('mod/partforum:splitdiscussions', $modcontext);
        $cm->cache->caps['mod/partforum:deleteownpost']    = has_capability('mod/partforum:deleteownpost', $modcontext);
        $cm->cache->caps['mod/partforum:deleteanypost']    = has_capability('mod/partforum:deleteanypost', $modcontext);
        $cm->cache->caps['mod/partforum:viewanyrating']    = has_capability('mod/partforum:viewanyrating', $modcontext);
        $cm->cache->caps['mod/partforum:exportpost']       = has_capability('mod/partforum:exportpost', $modcontext);
        $cm->cache->caps['mod/partforum:exportownpost']    = has_capability('mod/partforum:exportownpost', $modcontext);
    }

    if (!isset($cm->uservisible)) {
	// updated on 20151026 by Murphy
        // $cm->uservisible = coursemodule_visible_for_user($cm);
	$cm->uservisible = \core_availability\info_module::is_user_visible($cm, 0, false);
    }

    if ($istracked && is_null($postisread)) {
        $postisread = partforum_tp_is_post_read($USER->id, $post);
    }

    if (!partforum_user_can_see_post($partforum, $discussion, $post, NULL, $cm)) {
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }
        $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
        $output .= html_writer::start_tag('div', array('class'=>"forumpost clearfix "));
        $output .= html_writer::start_tag('div', array('class'=>'row header'));
        $output .= html_writer::tag('div', '', array('class'=>'left picture')); // Picture
        if ($post->parent) {
            $output .= html_writer::start_tag('div', array('class'=>'topic'));
        } else {
            $output .= html_writer::start_tag('div', array('class'=>'topic starter'));
        }
        $output .= html_writer::tag('div', get_string('partforumsubjecthidden','partforum'), array('class'=>'subject')); // Subject
        $output .= html_writer::tag('div', get_string('partforumauthorhidden','partforum'), array('class'=>'author')); // author
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::start_tag('div', array('class'=>'row'));
        $output .= html_writer::tag('div', '&nbsp;', array('class'=>'left side')); // Groups
        $output .= html_writer::tag('div', get_string('partforumbodyhidden','partforum'), array('class'=>'content')); // Content
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::end_tag('div'); // partforumpost

        if ($return) {
            return $output;
        }
        echo $output;
        return;
    }

    if (empty($str)) {
        $str = new stdClass;
        $str->edit         = get_string('edit', 'partforum');
        $str->delete       = get_string('delete', 'partforum');
        $str->reply        = get_string('reply', 'partforum');
        $str->parent       = get_string('parent', 'partforum');
        $str->pruneheading = get_string('pruneheading', 'partforum');
        $str->prune        = get_string('prune', 'partforum');
        $str->displaymode     = get_user_preferences('partforum_displaymode', $CFG->forum_displaymode);
        $str->markread     = get_string('markread', 'partforum');
        $str->markunread   = get_string('markunread', 'partforum');
    }

    $discussionlink = new moodle_url('/mod/partforum/discuss.php', array('d'=>$post->discussion));

    // Build an object that represents the posting user
    $postuser = new stdClass;
    //----updated by hema
    $postuser->id        = $post->userid;
    $postuserfields = explode(',', user_picture::fields());
    $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
    //$postuser->firstname = $post->firstname;
    //$postuser->lastname  = $post->lastname;
    //$postuser->imagealt  = $post->imagealt;
    //$postuser->picture   = $post->picture;
    //$postuser->email     = $post->email;

    // Some handy things for later on
    $postuser->fullname    = fullname($postuser, $cm->cache->caps['moodle/site:viewfullnames']);
    $postuser->profilelink = new moodle_url('/user/view.php', array('id'=>$post->userid, 'course'=>$course->id));

    // Prepare the groups the posting user belongs to
    if (isset($cm->cache->usersgroups)) {
        $groups = array();
        if (isset($cm->cache->usersgroups[$post->userid])) {
            foreach ($cm->cache->usersgroups[$post->userid] as $gid) {
                $groups[$gid] = $cm->cache->groups[$gid];
            }
        }
    } else {
        $groups = groups_get_all_groups($course->id, $post->userid, $cm->groupingid);
    }

    // Prepare the attachements for the post, files then images
    list($attachments, $attachedimages) = partforum_print_attachments($post, $cm, 'separateimages');

    // Determine if we need to shorten this post
    $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->partforum_longpost));


    // Prepare an array of commands
    $commands = array();

    // SPECIAL CASE: The front page can display a news item post to non-logged in users.
    // Don't display the mark read / unread controls in this case.
    if ($istracked && $CFG->partforum_usermarksread && isloggedin()) {
        $url = new moodle_url($discussionlink, array('postid'=>$post->id, 'mark'=>'unread'));
        $text = $str->markunread;
        if (!$postisread) {
            $url->param('mark', 'read');
            $text = $str->markread;
        }
        if ($str->displaymode == PARTFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->id);
        }
        $commands[] = array('url'=>$url, 'text'=>$text);
    }

    // Zoom in to the parent specifically
    if ($post->parent) {
        $url = new moodle_url($discussionlink);
        if ($str->displaymode == PARTFORUM_MODE_THREADED) {
            $url->param('parent', $post->parent);
        } else {
            $url->set_anchor('p'.$post->parent);
        }
        $commands[] = array('url'=>$url, 'text'=>$str->parent);
    }
    
    // hiding all replies of a specific post
    if( $DB->record_exists_sql("select * from {partforum_posts} where parent=$post->id") && $CFG->partforum_enablehidelink){        
        $commands[] = array('url'=>'javascript:void(0)','text'=>"<span id=hide_rply_$post->id  onclick=hide_all_replies($post->id)>". get_string('hidethisspecificpost_reply','partforum')."</span>", 'onclick'=>"hide all replies($post->id)");        
    }
    

    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    $age = time() - $post->created;
    if (!$post->parent && $partforum->type == 'news' && $discussion->timestart > time()) {
        $age = 0;
    }
    if (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/partforum:editanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/partforum/post.php', array('edit'=>$post->id)), 'text'=>$str->edit);
    }

    if ($cm->cache->caps['mod/partforum:splitdiscussions'] && $post->parent && $partforum->type != 'single') {
        $commands[] = array('url'=>new moodle_url('/mod/partforum/post.php', array('prune'=>$post->id)), 'text'=>$str->prune, 'title'=>$str->pruneheading);
    }

    if (($ownpost && $age < $CFG->maxeditingtime && $cm->cache->caps['mod/partforum:deleteownpost']) || $cm->cache->caps['mod/partforum:deleteanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/partforum/post.php', array('delete'=>$post->id)), 'text'=>$str->delete);
    }

    if ($reply) {
        $commands[] = array('url'=>new moodle_url('/mod/partforum/post.php', array('reply'=>$post->id)), 'text'=>$str->reply);
    }

    if ($CFG->enableportfolios && ($cm->cache->caps['mod/partforum:exportpost'] || ($ownpost && $cm->cache->caps['mod/partforum:exportownpost']))) {
        $p = array('postid' => $post->id);
        require_once($CFG->libdir.'/portfoliolib.php');
        $button = new portfolio_add_button();
        $button->set_callback_options('partforum_portfolio_caller', array('postid' => $post->id), '/mod/partforum/locallib.php');
        if (empty($attachments)) {
            $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
        } else {
            $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
        }

        $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
        if (!empty($porfoliohtml)) {
            $commands[] = $porfoliohtml;
        }
    }
    // Finished building commands

    global $PAGE;

    $PAGE->requires->js('/mod/partforum/js/partforum_custom.js');
    
    if($CFG->partforum_enabletoggle_forallposts)
    $PAGE->requires->js_init_call('partforum_post_toggle', array($post->id));
    // Begin output

    $output  = '';

    if ($istracked) {
        if ($postisread) {
            $partforumpostclass = ' read';
        } else {
            $partforumpostclass = ' unread';
            $output .= html_writer::tag('a', '', array('name'=>'unread'));
        }
    } else {
        // ignore trackign status if not tracked or tracked param missing
        $partforumpostclass = '';
    }

    $topicclass = '';
    if (empty($post->parent)) {
        $topicclass = ' firstpost starter';
    }

    $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
    $output .= html_writer::start_tag('div', array('class'=>"forumpost clearfix$partforumpostclass.$topicclass hide_all_reply$post->parent"));
    $output .= html_writer::start_tag('div', array('class'=>"row header clearfix",'id'=>'header_partforum'.$post->id));
    
    $output .= html_writer::start_tag('div', array('class'=>'left picture'));
    $output .= $OUTPUT->user_picture($postuser, array('courseid'=>$course->id));
    $output .= html_writer::end_tag('div');


    $output .= html_writer::start_tag('div', array('class'=>'topic'.$topicclass));

    $postsubject = $post->subject;
    if (empty($post->subjectnoformat)) {
        $postsubject = format_string($postsubject);
    }
    $output .= html_writer::tag('div', $postsubject, array('class'=>'subject'));

    $by = new stdClass();
    $by->name = html_writer::link($postuser->profilelink, $postuser->fullname);
    $by->date = userdate($post->modified);
    
    if($CFG->partforum_enabletoggle_forallposts)
    $style='float:left';
    else
    $style='';
    
    $output .= html_writer::tag('div', get_string('bynameondate', 'partforum', $by), array('class'=>'author','id'=>'partforum_author','style'=>$style));
    
    if($CFG->partforum_enabletoggle_forallposts)
    $output .= '<div id=partform_img'.$post->id.'><img src="'.$OUTPUT->pix_url('t/expanded') . '"  /></div>';

    $output .= html_writer::end_tag('div'); //topic
    $output .= html_writer::end_tag('div'); //row

    $output .= html_writer::start_tag('div', array('class'=>"row maincontent clearfix ",'id'=>'row_partforum_maincontent'.$post->id));
    $output .= html_writer::start_tag('div', array('class'=>'left'));

    $groupoutput = '';
    if ($groups) {
        $groupoutput = print_group_picture($groups, $course->id, false, true, true);
    }
    if (empty($groupoutput)) {
        $groupoutput = '&nbsp;';
    }
    $output .= html_writer::tag('div', $groupoutput, array('class'=>'grouppictures'));

    $output .= html_writer::end_tag('div'); //left side
    $output .= html_writer::start_tag('div', array('class'=>'no-overflow'));
    $output .= html_writer::start_tag('div', array('class'=>'content'));
    if (!empty($attachments)) {
        $output .= html_writer::tag('div', $attachments, array('class'=>'attachments'));
    }

    $options = new stdClass;
    $options->para    = false;
    $options->trusted = $post->messagetrust;
    $options->context = $modcontext;
    // print_object($post);
    
    //----------- based on the settings enabling link(updated by hema)----------------------
    //if((isset($post->partforumtype)) && $post->partforumtype=='participation'){
    if( $post->parent==0){
        if($partforum->assesstimefinish==0)
           $post->message .= get_string('partforum_baselineswithoutdates','partforum');
        else
           $post->message .= get_string('partforum_instructions_baselines','partforum',userdate($partforum->assesstimefinish, get_string('strftimedate', 'langconfig'))); 

        $enablepopup=(isset($CFG->partforum_enablepopup)?$CFG->partforum_enablepopup:0);
        
      //----rating image popup-----------------------------------------------------  
        $imagesrc=$OUTPUT->pix_url('partforum_rating','partforum');
                       //$PAGE->requires->event_handler('.partforum_rating_img', 'click', 'show_instruction_dialog',
                       // array('message' => "<img id='partform_img' src=$imagesrc />",'heading'=>get_string('graph_heading','partforum'))); 
        
        if($enablepopup <=0){    
              //$post->message .=get_string('partforum_instructions','partforum',userdate($partforum->assesstimefinish,get_string('strftimedate', 'langconfig')));
              $post->message .= $CFG->partforum_instructions;
        } 
        else{    
            //$PAGE->requires->string_for_js('popup_heading', 'partforum');
            $post->message .=get_string('partforum_instructions_link','partforum',$post->id);
                        $PAGE->requires->event_handler('.partforum_instrction_link'.$post->id, 'click', 'show_instruction_dialog',
                        array('message' => $CFG->partforum_instructions,'heading'=>get_string('popup_heading','partforum')));
                        
                        
                            
        }
    } // end of if condition
    //------------------------------------------------------------------------    

    if ($shortenpost) {
        // Prepare shortened version
        $postclass    = 'shortenedpost';
       $postcontent  = format_text(partforum_shorten_post($post->message), $post->messageformat, $options, $course->id);
        $postcontent .= html_writer::link($discussionlink, get_string('readtherest', 'partforum'));
        $postcontent .= html_writer::tag('span', '('.get_string('numwords', 'moodle', count_words(strip_tags($post->message))).')...', array('class'=>'post-word-count'));
    } else {
        // Prepare whole post
        $postclass    = 'fullpost';
        $postcontent  = format_text($post->message, $post->messageformat, $options, $course->id);
        if (!empty($highlight)) {
            $postcontent = highlight($highlight, $postcontent);
        }
        $postcontent .= html_writer::tag('div', $attachedimages, array('class'=>'attachedimages'));
    }
    // Output the post content
    $output .= html_writer::tag('div', $postcontent, array('class'=>'posting '.$postclass));
    $output .= html_writer::end_tag('div'); // Content
    $output .= html_writer::end_tag('div'); // Content mask
    $output .= html_writer::end_tag('div'); // Row

    $output .= html_writer::start_tag('div', array('class'=>'row side'));
    $output .= html_writer::tag('div','&nbsp;', array('class'=>'left'));
    $output .= html_writer::start_tag('div', array('class'=>"options clearfix"));

    // Output ratings
    if (!empty($post->rating)) {
        $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class'=>'partforum-post-rating'));
    }   
    
    //print_object($commands);
    
    // Output the commands
    $commandhtml = array();
    foreach ($commands as $command) {
        if (is_array($command)) {
            $commandhtml[] = html_writer::link($command['url'], $command['text']);
        } else {
            $commandhtml[] = $command;
        }
    }
    $output .= html_writer::tag('div', implode(' | ', $commandhtml), array('class'=>'commands','id'=>'partforum_actions_button'));

    // Output link to post if required
    if ($link) {
        if ($post->replies == 1) {
            $replystring = get_string('repliesone', 'partforum', $post->replies);
        } else {
            $replystring = get_string('repliesmany', 'partforum', $post->replies);
        }

        $output .= html_writer::start_tag('div', array('class'=>'link'));
        $output .= html_writer::link($discussionlink, get_string('discussthistopic', 'partforum'));
        $output .= '&nbsp;('.$replystring.')';
        $output .= html_writer::end_tag('div'); // link
    }

    

    // Output footer if required
    if ($footer) {
        $output .= html_writer::tag('div', $footer, array('class'=>'footer'));
    }

    // Close remaining open divs
    $output .= html_writer::end_tag('div'); // content

    $output .= html_writer::end_tag('div'); // row
    $output .= html_writer::end_tag('div'); // partforumpost

    // Mark the partforum post as read if required
    if ($istracked && !$CFG->partforum_usermarksread && !$postisread) {
        partforum_tp_mark_post_read($USER->id, $post, $partforum->id);
    }

    if ($return) {
        return $output;
    }
    echo $output;
    return;
}

/**
 * Return rating related permissions
 *
 * @param string $options the context id
 * @return array an associative array of the user's rating permissions
 */
function partforum_rating_permissions($contextid, $component, $ratingarea) {
    $context = get_context_instance_by_id($contextid, MUST_EXIST);
    if ($component != 'mod_partforum' || $ratingarea != 'post') {
        // We don't know about this component/ratingarea so just return null to get the
        // default restrictive permissions.
        return null;
    }
    return array(
        'view'    => has_capability('mod/partforum:viewrating', $context),
        'viewany' => has_capability('mod/partforum:viewanyrating', $context),
        'viewall' => has_capability('mod/partforum:viewallratings', $context),
        'rate'    => has_capability('mod/partforum:rate', $context)
    );
}

/**
 * Validates a submitted rating
 * @param array $params submitted data
 *            context => object the context in which the rated items exists [required]
 *            component => The component for this module - should always be mod_partforum [required]
 *            ratingarea => object the context in which the rated items exists [required]
 *            itemid => int the ID of the object being rated [required]
 *            scaleid => int the scale from which the user can select a rating. Used for bounds checking. [required]
 *            rating => int the submitted rating [required]
 *            rateduserid => int the id of the user whose items have been rated. NOT the user who submitted the ratings. 0 to update all. [required]
 *            aggregation => int the aggregation method to apply when calculating grades ie RATING_AGGREGATE_AVERAGE [required]
 * @return boolean true if the rating is valid. Will throw rating_exception if not
 */
function partforum_rating_validate($params) {
    global $DB, $USER;

    // Check the component is mod_partforum
    if ($params['component'] != 'mod_partforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in partforum)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call partforum_user_can_see_post
    $post = $DB->get_record('partforum_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('partforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $partforum = $DB->get_record('partforum', array('id' => $discussion->partforum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $partforum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('partforum', $partforum->id, $course->id , false, MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Make sure the context provided is the context of the partforum
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($partforum->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($partforum->assesstimestart) && !empty($partforum->assesstimefinish)) {
        if ($post->created < $partforum->assesstimestart || $post->created > $partforum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($partforum->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$partforum->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $partforum->scale) {
        //if its numeric and submitted rating is above maximum
        throw new rating_exception('invalidnum');
    }

    // Make sure groups allow this user to see the item they're rating
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            throw new rating_exception('cannotfindgroup');//something is wrong
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not allow rating of posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            throw new rating_exception('notmemberofgroup');
        }
    }

    // perform some final capability checks
    if (!partforum_user_can_see_post($partforum, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}


/**
 * This function prints the overview of a discussion in the partforum listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: partforum_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $partforum The partforum object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this partforum.
 * @param boolean $partforumtracked Is the user tracking this partforum.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 */
function partforum_print_discussion_header(&$post, $partforum, $group=-1, $datestring="",
                                        $cantrack=true, $partforumtracked=true, $canviewparticipants=true, $modcontext=NULL) {

    global $USER, $CFG, $OUTPUT;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = context_module::instance($cm->id);
    }

    if (!isset($rowcount)) {
        $rowcount = 0;
        $strmarkalldread = get_string('markalldread', 'partforum');
    } else {
        $rowcount = ($rowcount + 1) % 2;
    }

    $post->subject = format_string($post->subject,true);

    echo "\n\n";
    echo '<tr class="discussion r'.$rowcount.'">';

    // Topic
    echo '<td class="topic starter">';
    echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'">'.$post->subject.'</a>';
    echo "</td>\n";

    // Picture old code
   // $postuser = new stdClass();
  
    //$postuser->id = $post->userid;
    //$postuser->firstname = $post->firstname;
    //$postuser->lastname = $post->lastname;
    //$postuser->imagealt = $post->imagealt;
    //$postuser->picture = $post->picture;
    //$postuser->email = $post->email;
    // $postuserfields = explode(',', user_picture::fields());
    //$postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);

     // Picture updated by hema
    $postuser = new stdClass();
    $postuserfields = explode(',', user_picture::fields());
    $postuser = username_load_fields_from_object($postuser, $post, null, $postuserfields);
    $postuser->id = $post->userid;  
    
    echo '<td class="picture">';
    echo $OUTPUT->user_picture($postuser, array('courseid'=>$partforum->course));
    echo "</td>\n";

    // User name
    
   // $fullname = fullname($postuser, has_capability('moodle/site:viewfullnames', $modcontext));
    $fullname = fullname($postuser, has_capability('moodle/site:viewfullnames', $modcontext));
    echo '<td class="author">';
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$partforum->course.'">'.$fullname.'</a>';
    echo "</td>\n";

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            print_group_picture($group, $partforum->course, false, false, true);
        } else if (isset($group->id)) {
            if($canviewparticipants) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$partforum->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
            } else {
                echo $group->name;
            }
        }
        echo "</td>\n";
    }

    if (has_capability('mod/partforum:viewdiscussion', $modcontext)) {   // Show the column with replies
        echo '<td class="replies">';
        echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'">';
        echo $post->replies.'</a>';
        echo "</td>\n";

        if ($cantrack) {
            echo '<td class="replies">';
            if ($partforumtracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/partforum/markposts.php?f='.
                         $partforum->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php">' .
                         '<img src="'.$OUTPUT->pix_url('t/clear') . '" class="iconsmall" alt="'.$strmarkalldread.'" /></a>';
                    echo '</span>';
                } else {
                    echo '<span class="read">';
                    echo $post->unread;
                    echo '</span>';
                }
            } else {
                echo '<span class="read">';
                echo '-';
                echo '</span>';
            }
            echo "</td>\n";
        }
    }

    echo '<td class="lastpost">';
    $usedate = (empty($post->timemodified)) ? $post->modified : $post->timemodified;  // Just in case
    $parenturl = (empty($post->lastpostid)) ? '' : '&amp;parent='.$post->lastpostid;
    $usermodified = new stdClass();
    $usermodified->id        = $post->usermodified;
    $usermodified->firstname = $post->umfirstname;
    $usermodified->lastname  = $post->umlastname;
    //----- added new functions by hema
    $usermodified = username_load_fields_from_object($usermodified, $post,'um');
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$partforum->course.'">'.
         fullname($usermodified).'</a><br />';
    echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate($usedate, $datestring).'</a>';
    echo "</td>\n";

    echo "</tr>\n\n";

}


/**
 * Given a post object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->partforum_longpost and $CFG->partforum_shortpost
 *
 * @global object
 * @param string $message
 * @return string
 */
function partforum_shorten_post($message) {

   global $CFG;

   $i = 0;
   $tag = false;
   $length = strlen($message);
   $count = 0;
   $stopzone = false;
   $truncate = 0;

   for ($i=0; $i<$length; $i++) {
       $char = $message[$i];

       switch ($char) {
           case "<":
               $tag = true;
               break;
           case ">":
               $tag = false;
               break;
           default:
               if (!$tag) {
                   if ($stopzone) {
                       if ($char == ".") {
                           $truncate = $i+1;
                           break 2;
                       }
                   }
                   $count++;
               }
               break;
       }
       if (!$stopzone) {
           if ($count > $CFG->partforum_shortpost) {
               $stopzone = true;
           }
       }
   }

   if (!$truncate) {
       $truncate = $i;
   }

   return substr($message, 0, $truncate);
}

/**
 * Print the drop down that allows the user to select how they want to have
 * the discussion displayed.
 *
 * @param int $id partforum id if $partforumtype is 'single',
 *              discussion id for any other partforum type
 * @param mixed $mode partforum layout mode
 * @param string $partforumtype optional
 */
function partforum_print_mode_form($id, $mode, $partforumtype='') {
    global $OUTPUT;
    if ($partforumtype == 'single') {
        $select = new single_select(new moodle_url("/mod/partforum/view.php", array('f'=>$id)), 'mode', partforum_get_layout_modes(), $mode, null, "mode");
        $select->class = "partforummode";
    } else {
        $select = new single_select(new moodle_url("/mod/partforum/discuss.php", array('d'=>$id)), 'mode', partforum_get_layout_modes(), $mode, null, "mode");
    }
    echo $OUTPUT->render($select);
}

/**
 * @global object
 * @param object $course
 * @param string $search
 * @return string
 */
function partforum_search_form($course, $search='') {
    global $CFG, $OUTPUT;

    $output  = '<div class="partforumsearch">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/partforum/search.php" style="display:inline">';
    $output .= '<fieldset class="invisiblefieldset">';
    $output .= $OUTPUT->help_icon('search');
    $output .= '<label class="accesshide" for="search" >'.get_string('search', 'partforum').'</label>';
    $output .= '<input id="search" name="search" type="text" size="18" value="'.s($search, true).'" alt="search" />';
    $output .= '<label class="accesshide" for="searchpartforums" >'.get_string('searchpartforums', 'partforum').'</label>';
    $output .= '<input id="searchpartforums" value="'.get_string('searchpartforums', 'partforum').'" type="submit" />';
    $output .= '<input name="id" type="hidden" value="'.$course->id.'" />';
    $output .= '</fieldset>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}


/**
 * @global object
 * @global object
 */
function partforum_set_return() {
    global $CFG, $SESSION;

    if (! isset($SESSION->fromdiscussion)) {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referer = $_SERVER['HTTP_REFERER'];
        } else {
            $referer = "";
        }
        // If the referer is NOT a login screen then save it.
        if (! strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $_SERVER["HTTP_REFERER"];
        }
    }
}


/**
 * @global object
 * @param string $default
 * @return string
 */
function partforum_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);
        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Given a discussion object that is being moved to $partforumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new partforum directory.
 *
 * @global object
 * @param object $discussion
 * @param int $partforumfrom source partforum id
 * @param int $partforumto target partforum id
 * @return bool success
 */
function partforum_move_attachments($discussion, $partforumfrom, $partforumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('partforum', $partforumto);
    $oldcm = get_coursemodule_from_instance('partforum', $partforumfrom);

    $newcontext = context_module::instance($newcm->id);
    $oldcontext = context_module::instance($oldcm->id);
    
  //  $newcontext = get_context_instance(CONTEXT_MODULE, $newcm->id);
  //  $oldcontext = get_context_instance(CONTEXT_MODULE, $oldcm->id);

    // loop through all posts, better not use attachment flag ;-)
    if ($posts = $DB->get_records('partforum_posts', array('discussion'=>$discussion->id), '', 'id, attachment')) {
        foreach ($posts as $post) {
            $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_partforum', 'post', $post->id);
            $attachmentsmoved = $fs->move_area_files_to_new_context($oldcontext->id,
                    $newcontext->id, 'mod_partforum', 'attachment', $post->id);
            if ($attachmentsmoved > 0 && $post->attachment != '1') {
                // Weird - let's fix it
                $post->attachment = '1';
                $DB->update_record('partforum_posts', $post);
            } else if ($attachmentsmoved == 0 && $post->attachment != '') {
                // Weird - let's fix it
                $post->attachment = '';
                $DB->update_record('partforum_posts', $post);
            }
        }
    }

    return true;
}

/**
 * Returns attachments as formated text/html optionally with separate images
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param object $cm
 * @param string $type html/text/separateimages
 * @return mixed string or array of (html text withouth images and image HTML)
 */
function partforum_print_attachments($post, $cm, $type) {
    global $CFG, $DB, $USER, $OUTPUT;

    if (empty($post->attachment)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!in_array($type, array('separateimages', 'html', 'text'))) {
        return $type !== 'separateimages' ? '' : array('', '');
    }

    if (!$context = context_module::instance($cm->id)) {
        return $type !== 'separateimages' ? '' : array('', '');
    }
    $strattachment = get_string('attachment', 'partforum');

    $fs = get_file_storage();

    $imagereturn = '';
    $output = '';

    $canexport = !empty($CFG->enableportfolios) && (has_capability('mod/partforum:exportpost', $context) || ($post->userid == $USER->id && has_capability('mod/partforum:exportownpost', $context)));

    if ($canexport) {
        require_once($CFG->libdir.'/portfoliolib.php');
    }

    $files = $fs->get_area_files($context->id, 'mod_partforum', 'attachment', $post->id, "timemodified", false);
    if ($files) {
        if ($canexport) {
            $button = new portfolio_add_button();
        }
        foreach ($files as $file) {
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            $iconimage = '<img src="'.$OUTPUT->pix_url(file_mimetype_icon($mimetype)).'" class="icon" alt="'.$mimetype.'" />';
            $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$context->id.'/mod_partforum/attachment/'.$post->id.'/'.$filename);

            if ($type == 'html') {
                $output .= "<a href=\"$path\">$iconimage</a> ";
                $output .= "<a href=\"$path\">".s($filename)."</a>";
                if ($canexport) {
                    $button->set_callback_options('partforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), '/mod/partforum/locallib.php');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= "<br />";

            } else if ($type == 'text') {
                $output .= "$strattachment ".s($filename).":\n$path\n";

            } else { //'returnimages'
                if (in_array($mimetype, array('image/gif', 'image/jpeg', 'image/png'))) {
                    // Image attachments don't get printed as links
                    $imagereturn .= "<br /><img src=\"$path\" alt=\"\" />";
                    if ($canexport) {
                        $button->set_callback_options('partforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), '/mod/partforum/locallib.php');
                        $button->set_format_by_file($file);
                        $imagereturn .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                } else {
                    $output .= "<a href=\"$path\">$iconimage</a> ";
                    $output .= format_text("<a href=\"$path\">".s($filename)."</a>", FORMAT_HTML, array('context'=>$context));
                    if ($canexport) {
                        $button->set_callback_options('partforum_portfolio_caller', array('postid' => $post->id, 'attachment' => $file->get_id()), '/mod/partforum/locallib.php');
                        $button->set_format_by_file($file);
                        $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                    }
                    $output .= '<br />';
                }
            }
        }
    }

    if ($type !== 'separateimages') {
        return $output;

    } else {
        return array($output, $imagereturn);
    }
}

/**
 * Lists all browsable file areas
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @return array
 */
function partforum_get_file_areas($course, $cm, $context) {
    $areas = array();
    return $areas;
}

/**
 * Serves the partforum attachments. Implements needed access control ;-)
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function partforum_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $fileareas = array('attachment', 'post');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $postid = (int)array_shift($args);

    if (!$post = $DB->get_record('partforum_posts', array('id'=>$postid))) {
        return false;
    }

    if (!$discussion = $DB->get_record('partforum_discussions', array('id'=>$post->discussion))) {
        return false;
    }

    if (!$partforum = $DB->get_record('partforum', array('id'=>$cm->instance))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_partforum/$filearea/$postid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    // Make sure groups allow this user to see this file
    if ($discussion->groupid > 0 and $groupmode = groups_get_activity_groupmode($cm, $course)) {   // Groups are being used
        if (!groups_group_exists($discussion->groupid)) { // Can't find group
            return false;                           // Be safe and don't send it to anyone
        }

        if (!groups_is_member($discussion->groupid) and !has_capability('moodle/site:accessallgroups', $context)) {
            // do not send posts from other groups when in SEPARATEGROUPS or VISIBLEGROUPS
            return false;
        }
    }

    // Make sure we're allowed to see it...
    if (!partforum_user_can_see_post($partforum, $discussion, $post, NULL, $cm)) {
        return false;
    }


    // finally send the file
    send_stored_file($file, 0, 0, true); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and partforum
 * @param object $partforum
 * @param object $cm
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function partforum_add_attachment($post, $partforum, $cm, $mform=null, &$message=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = context_module::instance($cm->id);

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount']>0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'mod_partforum', 'attachment', $post->id);

    $DB->set_field('partforum_posts', 'attachment', $present, array('id'=>$post->id));

    return true;
}

/**
 * Add a new post in an existing discussion.
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return int
 */
function partforum_add_new_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('partforum_discussions', array('id' => $post->discussion));
    $partforum      = $DB->get_record('partforum', array('id' => $discussion->partforum));
    $cm         = get_coursemodule_from_instance('partforum', $partforum->id);
    $context    = context_module::instance($cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = "0";
    $post->userid     = $USER->id;
    $post->attachment = "";
	
	// update subject line for social comment
	if ($partforum->type == 'participation' && $post->replytype==1){
		$tempstr_scom = ' - '.get_string('socialcomment', 'partforum');
		if (strpos($post->subject, $tempstr_scom) === false) {
			$post->subject .= $tempstr_scom;	
		}
	}

    $post->id = $DB->insert_record("partforum_posts", $post);
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
    $DB->set_field('partforum_posts', 'message', $post->message, array('id'=>$post->id));
    partforum_add_attachment($post, $partforum, $cm, $mform, $message);

    // Update discussion modified date
    $DB->set_field("partforum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("partforum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (partforum_tp_can_track_partforums($partforum) && partforum_tp_is_tracked($partforum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->partforum);
    }
    
    /*
    * PARTICIPATION FORUM - Automatic Rating
    * @author Thomas Lextrait thomas.lextrait@gmail.com
    *
    * Don't add a rating to posts that have a reply type of "social comment"
    * replytye[0] = 'substantive contribution'; replytype[1] = 'social comment'
    */
    if ($partforum->type == 'participation' && $post->replytype==0){
		
		$partf_count = partforum_count_user_replies($partforum->id, $USER->id);

		if($partf_count <= 1){ // The post is already posted so the count should be 1 for first post
			partforum_post_add_rating($context->id, $USER->id, $post->id, 6, 10);
		}else{
			partforum_post_add_rating($context->id, $USER->id, $post->id, 10, 10);
		}
		
		// Make sure grades are recorded (we pass the entire partforum object to the method!)
		partforum_update_grades($partforum);    
	}

    return $post->id;
}

/**
 * Counts the total number of replies created by a given user in
 * an entire partforum. This counts the total number of posts that have
 * parents.
 * This function is used by the Participation Forum
 *
 * @author Thomas Lextrait thomas.lextrait@gmail.com
 *
 * @param $partforum_id
 * @param $user_id
 * @return int
 */
function partforum_count_user_replies($partforum_id, $user_id) {
	global $DB;
	
	$sql = "
		SELECT COUNT(post.id) AS postcount 
		FROM {partforum_posts} AS post, {partforum_discussions} AS disc
		WHERE post.userid=".$user_id." AND post.parent>0 AND disc.partforum=".$partforum_id." AND post.discussion=disc.id";
	
	$post_count = $DB->get_record_sql($sql, null);
	
	return $post_count->postcount;
}

/**
 * Adds a rating to the given user and given post
 * This function is used by the Participation Forum
 *
 * @author Thomas Lextrait thomas.lextrait@gmail.com
 *
 * @param $contextid
 * @param $userid
 * @param $postid
 * @param $rating
 * @param $scale
 */
function partforum_post_add_rating($contextid, $userid, $postid, $rating, $scale){

	global $DB;

	$data = new stdclass();
	$data->contextid    = $contextid;
    $data->scaleid      = $scale;
    $data->userid       = $userid;
    $data->itemid       = $postid;
    
    $data->component	= "mod_partforum";
    $data->ratingarea	= "post";
    
    $time = time();
    $data->timecreated = $time;
    $data->timemodified = $time;
	
	$data->rating = $rating;
				
	$DB->insert_record('rating', $data);
}

/**
 * Update a post
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function partforum_update_post($post, $mform, &$message) {
    global $USER, $CFG, $DB;

    $discussion = $DB->get_record('partforum_discussions', array('id' => $post->discussion));
    $partforum      = $DB->get_record('partforum', array('id' => $discussion->partforum));
    $cm         = get_coursemodule_from_instance('partforum', $partforum->id);
    $context    = context_module::instance($cm->id);

    $post->modified = time();

    $DB->update_record('partforum_posts', $post);

    $discussion->timemodified = $post->modified; // last modified tracking
    $discussion->usermodified = $post->userid;   // last modified tracking

    if (!$post->parent) {   // Post is a discussion starter - update discussion title and times too
        $discussion->name      = $post->subject;
        $discussion->timestart = $post->timestart;
        $discussion->timeend   = $post->timeend;
    }
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
    $DB->set_field('partforum_posts', 'message', $post->message, array('id'=>$post->id));

    $DB->update_record('partforum_discussions', $discussion);

    partforum_add_attachment($post, $partforum, $cm, $mform, $message);

    if (partforum_tp_can_track_partforums($partforum) && partforum_tp_is_tracked($partforum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->partforum);
    }

    return true;
}

/**
 * Given an object containing all the necessary data,
 * create a new discussion and return the id
 *
 * @global object
 * @global object
 * @global object
 * @param object $post
 * @param mixed $mform
 * @param string $message
 * @param int $userid
 * @return object
 */
function partforum_add_discussion($discussion, $mform=null, &$message=null, $userid=null) {
    global $USER, $CFG, $DB;

    $timenow = time();

    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post is stored as a real post, and linked
    // to from the discuss entry.

    $partforum = $DB->get_record('partforum', array('id'=>$discussion->partforum));
    $cm    = get_coursemodule_from_instance('partforum', $partforum->id);
    


    $post = new stdClass();
    $post->discussion    = 0;
    $post->parent        = 0;
    $post->userid        = $userid;
    $post->created       = $timenow;
    $post->modified      = $timenow;
    $post->mailed        = 0;
    $post->subject       = $discussion->name;
    $post->message       = $discussion->message;
    $post->messageformat = $discussion->messageformat;
    $post->messagetrust  = $discussion->messagetrust;
    $post->attachments   = isset($discussion->attachments) ? $discussion->attachments : null;
    $post->partforum         = $partforum->id;     // speedup
    $post->course        = $partforum->course; // speedup
    $post->mailnow       = $discussion->mailnow;
    
    /*
    * PARTICIPATION FORUM - Add the intro text to post
    */
 
    
    
    if ($partforum->type == 'participation'){
    	//$post->message .= "<br/>" .'<div class=mod_participation_instruction >'.$partforum->intro . get_string("partforumintro_default_partforum", "partforum", userdate($partforum->assesstimefinish, get_string('strftimedaydatetime', 'langconfig'))).'</div>';
        $post->message .= "<br/>" .$partforum->intro;
    }

    
    $post->id = $DB->insert_record("partforum_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = context_module::instance($cm->id);
        $text = file_save_draft_area_files($discussion->itemid, $context->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
        $DB->set_field('partforum_posts', 'message', $text, array('id'=>$post->id));
    }

    // Now do the main entry for the discussion, linking to this first post

    $discussion->firstpost    = $post->id;
    $discussion->timemodified = $timenow;
    $discussion->usermodified = $post->userid;
    $discussion->userid       = $userid;

    $post->discussion = $DB->insert_record("partforum_discussions", $discussion);

    // Finally, set the pointer on the post.
    $DB->set_field("partforum_posts", "discussion", $post->discussion, array("id"=>$post->id));

    if (!empty($cm->id)) {
        partforum_add_attachment($post, $partforum, $cm, $mform, $message);
    }

    if (partforum_tp_can_track_partforums($partforum) && partforum_tp_is_tracked($partforum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->partforum);
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire partforum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $partforum Forum
 * @return bool
 */
function partforum_delete_discussion($discussion, $fulldelete, $course, $cm, $partforum) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("partforum_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->partforum  = $discussion->partforum;
            if (!partforum_delete_post($post, 'ignore', $course, $cm, $partforum, $fulldelete)) {
                $result = false;
            }
        }
    }

    partforum_tp_delete_read_records(-1, -1, $discussion->id);

    if (!$DB->delete_records("partforum_discussions", array("id"=>$discussion->id))) {
        $result = false;
    }

    // Update completion state if we are tracking completion based on number of posts
    // But don't bother when deleting whole thing
    if (!$fulldelete) {
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
           ($partforum->completiondiscussions || $partforum->completionreplies || $partforum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single partforum post.
 *
 * @global object
 * @param object $post Forum post object
 * @param mixed $children Whether to delete children. If false, returns false
 *   if there are any children (without deleting the post). If true,
 *   recursively deletes all children. If set to special value 'ignore', deletes
 *   post regardless of children (this is for use only when deleting all posts
 *   in a disussion).
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $partforum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire partforum anyway.
 * @return bool
 */
function partforum_delete_post($post, $children, $course, $cm, $partforum, $skipcompletion=false) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($cm->id);

    if ($children != 'ignore' && ($childposts = $DB->get_records('partforum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               partforum_delete_post($childpost, true, $course, $cm, $partforum, $skipcompletion);
           }
       } else {
           return false;
       }
    }

    //delete ratings
    require_once($CFG->dirroot.'/rating/lib.php');
    $delopt = new stdClass;
    $delopt->contextid = $context->id;
    $delopt->component = 'mod_partforum';
    $delopt->ratingarea = 'post';
    $delopt->itemid = $post->id;
    $rm = new rating_manager();
    $rm->delete_ratings($delopt);
    
    //update grades (for participation partforum)
    partforum_update_grades($partforum);

    //delete attachments
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_partforum', 'attachment', $post->id);
    $fs->delete_area_files($context->id, 'mod_partforum', 'post', $post->id);

    if ($DB->delete_records("partforum_posts", array("id" => $post->id))) {

        partforum_tp_delete_read_records(-1, $post->id);

    // Just in case we are deleting the last post
        partforum_discussion_update_last_post($post->discussion);

        // Update completion state if we are tracking completion based on number of posts
        // But don't bother when deleting whole thing

        if (!$skipcompletion) {
            $completion = new completion_info($course);
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC &&
               ($partforum->completiondiscussions || $partforum->completionreplies || $partforum->completionposts)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $post->userid);
            }
        }

        return true;
    }
    return false;
}

/**
 * @global object
 * @param object $post
 * @param bool $children
 * @return int
 */
function partforum_count_replies($post, $children=true) {
    global $DB;
    $count = 0;

    if ($children) {
        if ($childposts = $DB->get_records('partforum_posts', array('parent' => $post->id))) {
           foreach ($childposts as $childpost) {
               $count ++;                   // For this child
               $count += partforum_count_replies($childpost, true);
           }
        }
    } else {
        $count += $DB->count_records('partforum_posts', array('parent' => $post->id));
    }

    return $count;
}


/**
 * @global object
 * @param int $partforumid
 * @param mixed $value
 * @return bool
 */
function partforum_forcesubscribe($partforumid, $value=1) {
    global $DB;
    return $DB->set_field("partforum", "forcesubscribe", $value, array("id" => $partforumid));
}

/**
 * @global object
 * @param object $partforum
 * @return bool
 */
function partforum_is_forcesubscribed($partforum) {
    global $DB;
    if (isset($partforum->forcesubscribe)) {    // then we use that
        return ($partforum->forcesubscribe == PARTFORUM_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('partforum', 'forcesubscribe', array('id' => $partforum)) == PARTFORUM_FORCESUBSCRIBE);
    }
}

function partforum_get_forcesubscribed($partforum) {
    global $DB;
    if (isset($partforum->forcesubscribe)) {    // then we use that
        return $partforum->forcesubscribe;
    } else {   // Check the database
        return $DB->get_field('partforum', 'forcesubscribe', array('id' => $partforum));
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $partforum
 * @return bool
 */
function partforum_is_subscribed($userid, $partforum) {
    global $DB;
    if (is_numeric($partforum)) {
        $partforum = $DB->get_record('partforum', array('id' => $partforum));
    }
    if (partforum_is_forcesubscribed($partforum)) {
        return true;
    }
    return $DB->record_exists("partforum_subscriptions", array("userid" => $userid, "partforum" => $partforum->id));
}

function partforum_get_subscribed_partforums($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {partforum} f
                   LEFT JOIN {partforum_subscriptions} fs ON (fs.partforum = f.id AND fs.userid = ?)
             WHERE f.forcesubscribe <> ".PARTFORUM_DISALLOWSUBSCRIBE."
                   AND (f.forcesubscribe = ".PARTFORUM_FORCESUBSCRIBE." OR fs.id IS NOT NULL)";
    if ($subscribed = $DB->get_records_sql($sql, array($USER->id))) {
        foreach ($subscribed as $s) {
            $subscribed[$s->id] = $s->id;
        }
        return $subscribed;
    } else {
        return array();
    }
}

/**
 * Adds user to the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $partforumid
 */
function partforum_subscribe($userid, $partforumid) {
    global $DB;

    if ($DB->record_exists("partforum_subscriptions", array("userid"=>$userid, "partforum"=>$partforumid))) {
        return true;
    }

    $sub = new stdClass();
    $sub->userid  = $userid;
    $sub->partforum = $partforumid;

    return $DB->insert_record("partforum_subscriptions", $sub);
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $partforumid
 */
function partforum_unsubscribe($userid, $partforumid) {
    global $DB;
    return $DB->delete_records("partforum_subscriptions", array("userid"=>$userid, "partforum"=>$partforumid));
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $partforum
 */
function partforum_post_subscription($post, $partforum) {

    global $USER;

    $action = '';
    $subscribed = partforum_is_subscribed($USER->id, $partforum);

    if ($partforum->forcesubscribe == PARTFORUM_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($partforum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', context_course::instance( $partforum->course), $USER->id)) {
        if ($subscribed) {
            $action = 'unsubscribe'; // sanity check, following MDL-14558
        } else {
            return "";
        }

    } else { // go with the user's choice
        if (isset($post->subscribe)) {
            // no change
            if ((!empty($post->subscribe) && $subscribed)
                || (empty($post->subscribe) && !$subscribed)) {
                return "";

            } elseif (!empty($post->subscribe) && !$subscribed) {
                $action = 'subscribe';

            } elseif (empty($post->subscribe) && $subscribed) {
                $action = 'unsubscribe';
            }
        }
    }

    $info = new stdClass();
    $info->name  = fullname($USER);
    $info->partforum = format_string($partforum->name);

    switch ($action) {
        case 'subscribe':
            partforum_subscribe($USER->id, $post->partforum);
            return "<p>".get_string("nowsubscribed", "partforum", $info)."</p>";
        case 'unsubscribe':
            partforum_unsubscribe($USER->id, $post->partforum);
            return "<p>".get_string("nownotsubscribed", "partforum", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a partforum.
 *
 * @param object $partforum the partforum. Fields used are $partforum->id and $partforum->forcesubscribe.
 * @param object $context the context object for this partforum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_partforums
 * @return string
 */
function partforum_get_subscribe_link($partforum, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_partforums=null) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'partforum'),
        'unsubscribed' => get_string('subscribe', 'partforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'partforum'),
        'cantsubscribe' => get_string('disallowsubscribe','partforum')
    );
    $messages = $messages + $defaultmessages;

    if (partforum_is_forcesubscribed($partforum)) {
        return $messages['forcesubscribed'];
    } else if ($partforum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE && !has_capability('mod/partforum:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }
        if (is_null($subscribed_partforums)) {
            $subscribed = partforum_is_subscribed($USER->id, $partforum);
        } else {
            $subscribed = !empty($subscribed_partforums[$partforum->id]);
        }
        if ($subscribed) {
            $linktext = $messages['subscribed'];
            $linktitle = get_string('subscribestop', 'partforum');
        } else {
            $linktext = $messages['unsubscribed'];
            $linktitle = get_string('subscribestart', 'partforum');
        }

        $options = array();
        if ($backtoindex) {
            $backtoindexlink = '&amp;backtoindex=1';
            $options['backtoindex'] = 1;
        } else {
            $backtoindexlink = '';
        }
        $link = '';

        if ($fakelink) {
            $PAGE->requires->js('/mod/partforum/partforum.js');
            $PAGE->requires->js_function_call('partforum_produce_subscribe_link', array($partforum->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options['id'] = $partforum->id;
        $options['sesskey'] = sesskey();
        $url = new moodle_url('/mod/partforum/subscribe.php', $options);
        $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));
        if ($fakelink) {
            $link .= '</noscript>';
        }

        return $link;
    }
}


/**
 * Generate and return the track or no track link for a partforum.
 *
 * @global object
 * @global object
 * @global object
 * @param object $partforum the partforum. Fields used are $partforum->id and $partforum->forcesubscribe.
 * @param array $messages
 * @param bool $fakelink
 * @return string
 */
function partforum_get_tracking_link($partforum, $messages=array(), $fakelink=true) {
    global $CFG, $USER, $PAGE, $OUTPUT;

    static $strnotrackpartforum, $strtrackpartforum;

    if (isset($messages['trackpartforum'])) {
         $strtrackpartforum = $messages['trackpartforum'];
    }
    if (isset($messages['notrackpartforum'])) {
         $strnotrackpartforum = $messages['notrackpartforum'];
    }
    if (empty($strtrackpartforum)) {
        $strtrackpartforum = get_string('trackpartforum', 'partforum');
    }
    if (empty($strnotrackpartforum)) {
        $strnotrackpartforum = get_string('notrackpartforum', 'partforum');
    }

    if (partforum_tp_is_tracked($partforum)) {
        $linktitle = $strnotrackpartforum;
        $linktext = $strnotrackpartforum;
    } else {
        $linktitle = $strtrackpartforum;
        $linktext = $strtrackpartforum;
    }

    $link = '';
    if ($fakelink) {
        $PAGE->requires->js('/mod/partforum/partforum.js');
        $PAGE->requires->js_function_call('partforum_produce_tracking_link', Array($partforum->id, $linktext, $linktitle));
        // use <noscript> to print button in case javascript is not enabled
        $link .= '<noscript>';
    }
    $url = new moodle_url('/mod/partforum/settracking.php', array('id'=>$partforum->id));
    $link .= $OUTPUT->single_button($url, $linktext, 'get', array('title'=>$linktitle));

    if ($fakelink) {
        $link .= '</noscript>';
    }

    return $link;
}



/**
 * Returns true if user created new discussion already
 *
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $userid
 * @return bool
 */
function partforum_user_has_posted_discussion($partforumid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {partforum_discussions} d, {partforum_posts} p
             WHERE d.partforum = ? AND p.discussion = d.id AND p.parent = 0 and p.userid = ?";

    return $DB->record_exists_sql($sql, array($partforumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $userid
 * @return array
 */
function partforum_discussions_user_has_posted_in($partforumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {partforum_posts} p,
                            {partforum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.partforum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($partforumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function partforum_user_has_posted($partforumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any partforum discussion?
        $sql = "SELECT 'x'
                  FROM {partforum_posts} p
                  JOIN {partforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.partforum = :partforumid";
        return $DB->record_exists_sql($sql, array('partforumid'=>$partforumid,'userid'=>$userid));
    } else {
        return $DB->record_exists('partforum_posts', array('discussion'=>$did,'userid'=>$userid));
    }
}

/**
 * Returns creation time of the first user's post in given discussion
 * @global object $DB
 * @param int $did Discussion id
 * @param int $userid User id
 * @return int|bool post creation time stamp or return false
 */
function partforum_get_user_posted_time($did, $userid) {
    global $DB;

    $posttime = $DB->get_field('partforum_posts', 'MIN(created)', array('userid'=>$userid, 'discussion'=>$did));
    if (empty($posttime)) {
        return false;
    }
    return $posttime;
}

/**
 * @global object
 * @param object $partforum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function partforum_user_can_post_discussion($partforum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $partforum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($partforum->type == 'news') {
        $capname = 'mod/partforum:addnews';
    } else {
        $capname = 'mod/partforum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($partforum->type == 'eachuser') {
        if (partforum_user_has_posted_discussion($partforum->id, $USER->id)) {
            return false;
        }
    }

    if (!$groupmode or has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($currentgroup) {
        return groups_is_member($currentgroup);
    } else {
        // no group membership and no accessallgroups means no new discussions
        // reverted to 1.7 behaviour in 1.9+,  buggy in 1.8.0-1.9.0
        return false;
    }
}

/**
 * This function checks whether the user can reply to posts in a partforum
 * discussion. Use partforum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $partforum partforum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function partforum_user_can_post($partforum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
    global $USER, $DB;
    if (empty($user)) {
        $user = $USER;
    }

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if (!isset($discussion->groupid)) {
        debugging('incorrect discussion parameter', DEBUG_DEVELOPER);
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $partforum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = context_module::instance($cm->id);
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($partforum->type == 'news') {
        $capname = 'mod/partforum:replynews';
    } else {
        $capname = 'mod/partforum:replypost';
    }

    if (!has_capability($capname, $context, $user->id)) {
        return false;
    }

    if (!$groupmode = groups_get_activity_groupmode($cm, $course)) {
        return true;
    }

    if (has_capability('moodle/site:accessallgroups', $context)) {
        return true;
    }

    if ($groupmode == VISIBLEGROUPS) {
        if ($discussion->groupid == -1) {
            // allow students to reply to all participants discussions - this was not possible in Moodle <1.8
            return true;
        }
        return groups_is_member($discussion->groupid);

    } else {
        //separate groups
        if ($discussion->groupid == -1) {
            return false;
        }
        return groups_is_member($discussion->groupid);
    }
}


/**
 * checks to see if a user can view a particular post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses SEPARATEGROUPS
 * @param object $post
 * @param object $course
 * @param object $cm
 * @param object $partforum
 * @param object $discussion
 * @param object $user
 */
function partforum_user_can_view_post($post, $course, $cm, $partforum, $discussion, $user=NULL){

    global $CFG, $USER;

    if (!$user){
        $user = $USER;
    }

    $modcontext = context_module::instance($cm->id);
    if (!has_capability('mod/partforum:viewdiscussion', $modcontext)) {
        return false;
    }

// If it's a grouped discussion, make sure the user is a member
    if ($discussion->groupid > 0) {
        $groupmode = groups_get_activity_groupmode($cm);
        if ($groupmode == SEPARATEGROUPS) {
            return groups_is_member($discussion->groupid) || has_capability('moodle/site:accessallgroups', $modcontext);
        }
    }
    return true;
}


/**
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @param object $partforum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function partforum_user_can_see_discussion($partforum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($partforum)) {
        debugging('missing full partforum', DEBUG_DEVELOPER);
        if (!$partforum = $DB->get_record('partforum',array('id'=>$partforum))) {
            return false;
        }
    }
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('partforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }

    if (!has_capability('mod/partforum:viewdiscussion', $context)) {
        return false;
    }

    if ($partforum->type == 'qanda' &&
            !partforum_user_has_posted($partforum->id, $discussion->id, $user->id) &&
            !has_capability('mod/partforum:viewqandawithoutposting', $context)) {
        return false;
    }
    return true;
}


/**
 * @global object
 * @global object
 * @param object $partforum
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function partforum_user_can_see_post($partforum, $discussion, $post, $user=NULL, $cm=NULL) {
    global $CFG, $USER, $DB;

    // retrieve objects (yuk)
    if (is_numeric($partforum)) {
        debugging('missing full partforum', DEBUG_DEVELOPER);
        if (!$partforum = $DB->get_record('partforum',array('id'=>$partforum))) {
            return false;
        }
    }

    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('partforum_discussions',array('id'=>$discussion))) {
            return false;
        }
    }
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('partforum_posts',array('id'=>$post))) {
            return false;
        }
    }
    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    if (isset($cm->cache->caps['mod/partforum:viewdiscussion'])) {
        if (!$cm->cache->caps['mod/partforum:viewdiscussion']) {
            return false;
        }
    } else {
        $modcontext = context_module::instance($cm->id);
        if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $user->id)) {
            return false;
        }
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
	// updated on 20151026 by Murphy
        // if (!coursemodule_visible_for_user($cm, $user->id)) {
	if (!\core_availability\info_module::is_user_visible($cm, $user->id, false)) {
            return false;
        }
    }

    if ($partforum->type == 'qanda') {
        $firstpost = partforum_get_firstpost_from_discussion($discussion->id);
        $modcontext = context_module::instance($cm->id);
        $userfirstpost = partforum_get_user_posted_time($discussion->id, $user->id);

        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)) ||
                $firstpost->id == $post->id || $post->userid == $user->id || $firstpost->userid == $user->id ||
                has_capability('mod/partforum:viewqandawithoutposting', $modcontext, $user->id, false));
    }
    return true;
}


/**
 * Prints the discussion view screen for a partforum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $partforum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the partforum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function partforum_print_latest_discussions($course, $partforum, $maxdiscussions=-1, $displayformat='plain', $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT;

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = context_module::instance($cm->id);

    if (empty($sort)) {
        $sort = "d.timemodified DESC";
    }

    $olddiscussionlink = false;

 // Sort out some defaults
    if ($perpage <= 0) {
        $perpage = 0;
        $page    = -1;
    }

    if ($maxdiscussions == 0) {
        // all discussions - backwards compatibility
        $page    = -1;
        $perpage = 0;
        if ($displayformat == 'plain') {
            $displayformat = 'header';  // Abbreviate display by default
        }

    } else if ($maxdiscussions > 0) {
        $page    = -1;
        $perpage = $maxdiscussions;
    }

    $fullpost = false;
    if ($displayformat == 'plain') {
        $fullpost = true;
    }


// Decide if current user is allowed to see ALL the current discussions or not

// First check the group stuff
    if ($currentgroup == -1 or $groupmode == -1) {
        $groupmode    = groups_get_activity_groupmode($cm, $course);
        $currentgroup = groups_get_activity_group($cm);
    }

    $groups = array(); //cache

// If the user can post discussions, then this is a good place to put the
// button for it. We do not show the button if we are showing site news
// and the current user is a guest.

    $canstart = partforum_user_can_post_discussion($partforum, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $partforum->type !== 'news') {
        if (isguestuser() or !isloggedin()) {
            $canstart = true;
        }
        if (!is_enrolled($context) and !is_viewing($context)) {
            // allow guests and not-logged-in to see the button - they are prompted to log in after clicking the link
            // normal users with temporary guest access see this button too, they are asked to enrol instead
            // do not show the button to users with suspended enrolments here
            $canstart = enrol_selfenrol_available($course->id);
        }
    }

    if ($canstart) {
        echo '<div class="singlebutton partforumaddnew">';
        echo "<form id=\"newdiscussionform\" method=\"get\" action=\"$CFG->wwwroot/mod/partforum/post.php\">";
        echo '<div>';
        echo "<input type=\"hidden\" name=\"partforum\" value=\"$partforum->id\" />";
        switch ($partforum->type) {
            case 'news':
            case 'blog':
                $buttonadd = get_string('addanewtopic', 'partforum');
                break;
            case 'qanda':
                $buttonadd = get_string('addanewquestion', 'partforum');
                break;
	        case 'participation':
	        	$buttonadd = get_string('addanewgrouppost', 'partforum');
	        	break;
            default:
                $buttonadd = get_string('addanewdiscussion', 'partforum');
                break;
        }
        echo '<input type="submit" value="'.$buttonadd.'" />';
        echo '</div>';
        echo '</form>';
        echo "</div>\n";

    } else if (isguestuser() or !isloggedin() or $partforum->type == 'news') {
        // no button and no info

    } else if ($groupmode and has_capability('mod/partforum:startdiscussion', $context)) {
        // inform users why they can not post new discussion
        if ($currentgroup) {
            echo $OUTPUT->notification(get_string('cannotadddiscussion', 'partforum'));
        } else {
            echo $OUTPUT->notification(get_string('cannotadddiscussionall', 'partforum'));
        }
    }

// Get all the recent discussions we're allowed to see

    $getuserlastmodified = ($displayformat == 'header');

    if (! $discussions = partforum_get_discussions($cm, $sort, $fullpost, null, $maxdiscussions, $getuserlastmodified, $page, $perpage) ) {
        echo '<div class="partforumnodiscuss">';
        if ($partforum->type == 'news') {
            echo '('.get_string('nonews', 'partforum').')';
        } else if ($partforum->type == 'qanda') {
            echo '('.get_string('noquestions','partforum').')';
        } else if ($partforum->type == 'participation') {
        	echo '('.get_string('nogroupposts', 'partforum').')';
        } else {
            echo '('.get_string('nodiscussions', 'partforum').')';
        }
        echo "</div>\n";
        return;
    }

// If we want paging
    if ($page != -1) {
        ///Get the number of discussions found
        $numdiscussions = partforum_get_discussions_count($cm);

        ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$partforum->id");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large partforums
            $replies = partforum_count_discussion_replies($partforum->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = partforum_count_discussion_replies($partforum->id);
        }

    } else {
        $replies = partforum_count_discussion_replies($partforum->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the partforum is tracked.
    if ($cantrack = partforum_tp_can_track_partforums($partforum)) {
        $partforumtracked = partforum_tp_is_tracked($partforum);
    } else {
        $partforumtracked = false;
    }

    if ($partforumtracked) {
        $unreads = partforum_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="partforumheaderlist" >';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'partforum').'</th>';
        echo '<th class="header author" colspan="2" scope="col">'.get_string('startedby', 'partforum').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/partforum:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'partforum').'</th>';
            // If the partforum can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'partforum');
                if ($partforumtracked) {
                    echo '&nbsp;<a title="'.get_string('markallread', 'partforum').
                         '" href="'.$CFG->wwwroot.'/mod/partforum/markposts.php?f='.
                         $partforum->id.'&amp;mark=read&amp;returnpage=view.php">'.
                         '<img src="'.$OUTPUT->pix_url('t/clear') . '" class="iconsmall" alt="'.get_string('markallread', 'partforum').'" /></a>';
                }
                echo '</th>';
            }
        }
        echo '<th class="header lastpost" scope="col">'.get_string('lastpost', 'partforum').'</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
    }

    foreach ($discussions as $discussion) {
        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // SPECIAL CASE: The front page can display a news item post to non-logged in users.
        // All posts are read in this case.
        if (!$partforumtracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        if (isloggedin()) {
            $ownpost = ($discussion->userid == $USER->id);
        } else {
            $ownpost=false;
        }
        // Use discussion name instead of subject of first post
        $discussion->subject = $discussion->name;

        switch ($displayformat) {
            case 'header':
                if ($groupmode > 0) {
                    if (isset($groups[$discussion->groupid])) {
                        $group = $groups[$discussion->groupid];
                    } else {
                        $group = $groups[$discussion->groupid] = groups_get_group($discussion->groupid);
                    }
                } else {
                    $group = -1;
                }
                partforum_print_discussion_header($discussion, $partforum, $group, $strdatestring, $cantrack, $partforumtracked,
                    $canviewparticipants, $context);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = context_module::instance($cm->id);
                    $link = partforum_user_can_post($partforum, $discussion, $USER, $cm, $course, $modcontext);
                }

                $discussion->partforum = $partforum->id;

                partforum_print_post($discussion, $discussion, $partforum, $cm, $course, $ownpost, 0, $link, false);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($partforum->type == 'news') {
            $strolder = get_string('oldertopics', 'partforum');
        } else {
            $strolder = get_string('olderdiscussions', 'partforum');
        }
        echo '<div class="partforumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$partforum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$partforum->id");
    }
}


/**
 * Prints a partforum discussion
 *
 * @uses CONTEXT_MODULE
 * @uses PARTFORUM_MODE_FLATNEWEST
 * @uses PARTFORUM_MODE_FLATOLDEST
 * @uses PARTFORUM_MODE_THREADED
 * @uses PARTFORUM_MODE_NESTED
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $partforum
 * @param stdClass $discussion
 * @param stdClass $post
 * @param int $mode
 * @param mixed $canreply
 * @param bool $canrate
 */
function partforum_print_discussion($course, $cm, $partforum, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {
    global $USER, $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ownpost = (isloggedin() && $USER->id == $post->userid);

    $modcontext = context_module::instance($cm->id);
    if ($canreply === NULL) {
        $reply = partforum_user_can_post($partforum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for partforum functions
    $cm->cache = new stdClass;
    $cm->cache->groups      = groups_get_all_groups($course->id, 0, $cm->groupingid);
    $cm->cache->usersgroups = array();

    $posters = array();

    // preload all posts - TODO: improve...
    if ($mode == PARTFORUM_MODE_FLATNEWEST) {
        $sort = "p.created DESC";
    } else {
        $sort = "p.created ASC";
    }

    $partforumtracked = partforum_tp_is_tracked($partforum);
    $posts = partforum_get_all_discussion_posts($discussion->id, $sort, $partforumtracked);
    $post = $posts[$post->id];

    foreach ($posts as $pid=>$p) {
        $posters[$p->userid] = $p->userid;
    }

    // preload all groups of ppl that posted in this discussion
    if ($postersgroups = groups_get_all_groups($course->id, $posters, $cm->groupingid, 'gm.id, gm.groupid, gm.userid')) {
        foreach($postersgroups as $pg) {
            if (!isset($cm->cache->usersgroups[$pg->userid])) {
                $cm->cache->usersgroups[$pg->userid] = array();
            }
            $cm->cache->usersgroups[$pg->userid][$pg->groupid] = $pg->groupid;
        }
        unset($postersgroups);
    }

    //load ratings
    if ($partforum->assessed!=RATING_AGGREGATE_NONE && $partforum->type!='participation') {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_partforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $partforum->assessed;//the aggregation method
        $ratingoptions->scaleid = $partforum->scale;
        $ratingoptions->userid = $USER->id;
        if ($partforum->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/partforum/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/partforum/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $partforum->assesstimestart;
        $ratingoptions->assesstimefinish = $partforum->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }


    $post->partforum = $partforum->id;   // Add the partforum id to the post object, later used by partforum_print_post
    $post->partforumtype = $partforum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);
    


    partforum_print_post($post, $discussion, $partforum, $cm, $course, $ownpost, $reply, false,
                         '', '', $postread, true, $partforumtracked);

    switch ($mode) {
        case PARTFORUM_MODE_FLATOLDEST :
        case PARTFORUM_MODE_FLATNEWEST :
        default:
            partforum_print_posts_flat($course, $cm, $partforum, $discussion, $post, $mode, $reply, $partforumtracked, $posts);
            break;

        case PARTFORUM_MODE_THREADED :
            partforum_print_posts_threaded($course, $cm, $partforum, $discussion, $post, 0, $reply, $partforumtracked, $posts);
            break;

        case PARTFORUM_MODE_NESTED :
            partforum_print_posts_nested($course, $cm, $partforum, $discussion, $post, $reply, $partforumtracked, $posts);
            break;
    }
}


/**
 * @global object
 * @global object
 * @uses PARTFORUM_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $partforum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param bool $reply
 * @param bool $partforumtracked
 * @param array $posts
 * @return void
 */
function partforum_print_posts_flat($course, &$cm, $partforum, $discussion, $post, $mode, $reply, $partforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if ($mode == PARTFORUM_MODE_FLATNEWEST) {
        $sort = "ORDER BY created DESC";
    } else {
        $sort = "ORDER BY created ASC";
    }

    foreach ($posts as $post) {
        if (!$post->parent) {
            continue;
        }
        $post->subject = format_string($post->subject);
        $ownpost = ($USER->id == $post->userid);

        $postread = !empty($post->postread);

        partforum_print_post($post, $discussion, $partforum, $cm, $course, $ownpost, $reply, $link,
                             '', '', $postread, true, $partforumtracked);
    }
}

/**
 * @todo Document this function
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return void
 */
function partforum_print_posts_threaded($course, &$cm, $partforum, $discussion, $parent, $depth, $reply, $partforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = context_module::instance($cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                partforum_print_post($post, $discussion, $partforum, $cm, $course, $ownpost, $reply, $link,
                                     '', '', $postread, true, $partforumtracked);
            } else {
                if (!partforum_user_can_see_post($partforum, $discussion, $post, NULL, $cm)) {
                    echo "</div>\n";
                    continue;
                }
                
            
                $by = new stdClass();
                  $usermodified = new stdClass();
               //  $usermodified =$post->userid;
                 $usermodified = username_load_fields_from_object($usermodified, $post);
                $by->name = fullname($usermodified, $canviewfullnames);
                $by->date = userdate($post->modified);

                if ($partforumtracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="partforumthread read">';
                    } else {
                        $style = '<span class="partforumthread unread">';
                    }
                } else {
                    $style = '<span class="partforumthread">';
                }
                echo $style."<a name=\"$post->id\"></a>".
                     "<a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a> ";
                print_string("bynameondate", "partforum", $by);
                echo "</span>";
            }

            partforum_print_posts_threaded($course, $cm, $partforum, $discussion, $post, $depth-1, $reply, $partforumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * @todo Document this function
 * @global object
 * @global object
 * @return void
 */
function partforum_print_posts_nested($course, &$cm, $partforum, $discussion, $parent, $reply, $partforumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if (!isloggedin()) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            $post->subject = format_string($post->subject);
            $postread = !empty($post->postread);

            partforum_print_post($post, $discussion, $partforum, $cm, $course, $ownpost, $reply, $link,
                                 '', '', $postread, true, $partforumtracked);
            partforum_print_posts_nested($course, $cm, $partforum, $discussion, $post, $reply, $partforumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * Returns all partforum posts since a given time in specified partforum.
 *
 * @todo Document this functions args
 * @global object
 * @global object
 * @global object
 * @global object
 */
function partforum_get_recent_mod_activity(&$activities, &$index, $timestart, $courseid, $cmid, $userid=0, $groupid=0)  {
    global $CFG, $COURSE, $USER, $DB;

    if ($COURSE->id == $courseid) {
        $course = $COURSE;
    } else {
        $course = $DB->get_record('course', array('id' => $courseid));
    }

    $modinfo =& get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $params = array($timestart, $cm->instance);

    if ($userid) {
        $userselect = "AND u.id = ?";
        $params[] = $userid;
    } else {
        $userselect = "";
    }

    if ($groupid) {
        $groupselect = "AND gm.groupid = ?";
        $groupjoin   = "JOIN {groups_members} gm ON  gm.userid=u.id";
        $params[] = $groupid;
    } else {
        $groupselect = "";
        $groupjoin   = "";
    }

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS partforumtype, d.partforum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture, u.imagealt, u.email
                                         FROM {partforum_posts} p
                                              JOIN {partforum_discussions} d ON d.id = p.discussion
                                              JOIN {partforum} f             ON f.id = d.partforum
                                              JOIN {user} u              ON u.id = p.userid
                                              $groupjoin
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = context_module::instance($cm->id);
    $viewhiddentimed = has_capability('mod/partforum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->partforum_enabletimedposts) and $USER->id != $post->duserid
          and (($post->timestart > 0 and $post->timestart > time()) or ($post->timeend > 0 and $post->timeend < time()))) {
            if (!$viewhiddentimed) {
                continue;
            }
        }

        if ($groupmode) {
            if ($post->groupid == -1 or $groupmode == VISIBLEGROUPS or $accessallgroups) {
                // oki (Open discussions have groupid -1)
            } else {
                // separate mode
                if (isguestuser()) {
                    // shortcut
                    continue;
                }

                if (!array_key_exists($post->groupid, $modinfo->groups[0])) {
                    continue;
                }
            }
        }

        $printposts[] = $post;
    }

    if (!$printposts) {
        return;
    }

    $aname = format_string($cm->name,true);

    foreach ($printposts as $post) {
        $tmpactivity = new stdClass();

        $tmpactivity->type         = 'partforum';
        $tmpactivity->cmid         = $cm->id;
        $tmpactivity->name         = $aname;
        $tmpactivity->sectionnum   = $cm->sectionnum;
        $tmpactivity->timestamp    = $post->modified;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->id         = $post->id;
        $tmpactivity->content->discussion = $post->discussion;
        $tmpactivity->content->subject    = format_string($post->subject);
        $tmpactivity->content->parent     = $post->parent;

        $tmpactivity->user = new stdClass();
        $tmpactivity->user->id        = $post->userid;
        $tmpactivity->user->firstname = $post->firstname;
        $tmpactivity->user->lastname  = $post->lastname;
        $tmpactivity->user->picture   = $post->picture;
        $tmpactivity->user->imagealt  = $post->imagealt;
        $tmpactivity->user->email     = $post->email;

        $activities[$index++] = $tmpactivity;
    }

    return;
}

/**
 * @todo Document this function
 * @global object
 */
function partforum_print_recent_mod_activity($activity, $courseid, $detail, $modnames, $viewfullnames) {
    global $CFG, $OUTPUT;

    if ($activity->content->parent) {
        $class = 'reply';
    } else {
        $class = 'discussion';
    }

    echo '<table border="0" cellpadding="3" cellspacing="0" class="partforum-recent">';

    echo "<tr><td class=\"userpicture\" valign=\"top\">";
    echo $OUTPUT->user_picture($activity->user, array('courseid'=>$courseid));
    echo "</td><td class=\"$class\">";

    echo '<div class="title">';
    if ($detail) {
        $aname = s($activity->name);
        echo "<img src=\"" . $OUTPUT->pix_url('icon', $activity->type) . "\" ".
             "class=\"icon\" alt=\"{$aname}\" />";
    }
    echo "<a href=\"$CFG->wwwroot/mod/partforum/discuss.php?d={$activity->content->discussion}"
         ."#p{$activity->content->id}\">{$activity->content->subject}</a>";
    echo '</div>';

    echo '<div class="user">';
    $fullname = fullname($activity->user, $viewfullnames);
    echo "<a href=\"$CFG->wwwroot/user/view.php?id={$activity->user->id}&amp;course=$courseid\">"
         ."{$fullname}</a> - ".userdate($activity->timestamp);
    echo '</div>';
      echo "</td></tr></table>";

    return;
}

/**
 * recursively sets the discussion field to $discussionid on $postid and all its children
 * used when pruning a post
 *
 * @global object
 * @param int $postid
 * @param int $discussionid
 * @return bool
 */
function partforum_change_discussionid($postid, $discussionid) {
    global $DB;
    $DB->set_field('partforum_posts', 'discussion', $discussionid, array('id' => $postid));
    if ($posts = $DB->get_records('partforum_posts', array('parent' => $postid))) {
        foreach ($posts as $post) {
            partforum_change_discussionid($post->id, $discussionid);
        }
    }
    return true;
}

/**
 * Prints the editing button on subscribers page
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param int $partforumid
 * @return string
 */
function partforum_update_subscriptions_button($courseid, $partforumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/partforum/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$partforumid\" />".
           "<input type=\"hidden\" name=\"edit\" value=\"$edit\" />".
           "<input type=\"submit\" value=\"$string\" /></form>";
}

/**
 * This function gets run whenever user is enrolled into course
 *
 * @param object $cp
 * @return void
 */
function partforum_user_enrolled($cp) {
    $context = context_course::instance($cp->courseid);
    partforum_add_user_default_subscriptions($cp->userid, $context);
}


/**
 * This function gets run whenever user is unenrolled from course
 *
 * @param object $cp
 * @return void
 */
function partforum_user_unenrolled($cp) {
    if ($cp->lastenrol) {
        $context = context_course::instance($cp->courseid);
        partforum_remove_user_subscriptions($cp->userid, $context);
        partforum_remove_user_tracking($cp->userid, $context);
    }
}


/**
 * Add subscriptions for new users
 *
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_COURSECAT
 * @uses PARTFORUM_INITIALSUBSCRIBE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function partforum_add_user_default_subscriptions($userid, $context) {
    global $DB;
    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
             $rs = $DB->get_recordset('course',null,'','id');
             foreach ($rs as $course) {
                 $subcontext = context_course::instance($course->id);
                 partforum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             break;

        case CONTEXT_COURSECAT:   // For a whole category
             $rs = $DB->get_recordset('course', array('category' => $context->instanceid),'','id');
             foreach ($rs as $course) {
                 $subcontext = context_course::instance($course->id);
                 partforum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid))) {
                 foreach ($categories as $category) {
                     $subcontext = context_instance(CONTEXT_COURSECAT, $category->id);
                     partforum_add_user_default_subscriptions($userid, $subcontext);
                 }
             }
             break;


        case CONTEXT_COURSE:   // For a whole course
             if (is_enrolled($context, $userid)) {
                if ($course = $DB->get_record('course', array('id' => $context->instanceid))) {
                     if ($partforums = get_all_instances_in_course('partforum', $course, $userid, false)) {
                         foreach ($partforums as $partforum) {
                             if ($partforum->forcesubscribe != PARTFORUM_INITIALSUBSCRIBE) {
                                 continue;
                             }
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $partforum->coursemodule)) {
                                 if (has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                     partforum_subscribe($userid, $partforum->id);
                                 }
                             }
                         }
                     }
                 }
             }
             break;

        case CONTEXT_MODULE:   // Just one partforum
            if (has_capability('mod/partforum:initialsubscriptions', $context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($partforum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if ($partforum->forcesubscribe != PARTFORUM_INITIALSUBSCRIBE) {
                             continue;
                         }
                         if (has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                             partforum_subscribe($userid, $partforum->id);
                         }
                     }
                 }
            }
            break;
    }

    return true;
}


/**
 * Remove subscriptions for a user in a context
 *
 * @global object
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSECAT
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_MODULE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function partforum_remove_user_subscriptions($userid, $context) {

    global $CFG, $DB;

    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
            // find all courses in which this user has a partforum subscription
            if ($courses = $DB->get_records_sql("SELECT c.id
                                                  FROM {course} c,
                                                       {partforum_subscriptions} fs,
                                                       {partforum} f
                                                       WHERE c.id = f.course AND f.id = fs.partforum AND fs.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                foreach ($courses as $course) {
                    $subcontext = context_course::instance($course->id);
                    partforum_remove_user_subscriptions($userid, $subcontext);
                }
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = context_course::instance($course->id);
                     partforum_remove_user_subscriptions($userid, $subcontext);
                 }
             }
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid), '', 'id')) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     partforum_remove_user_subscriptions($userid, $subcontext);
                 }
             }
             break;

        case CONTEXT_COURSE:   // For a whole course
            if (!is_enrolled($context, $userid)) {
                 if ($course = $DB->get_record('course', array('id' => $context->instanceid), 'id')) {
                    // find all partforums in which this user has a subscription, and its coursemodule id
                    if ($partforums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                         FROM {partforum} f,
                                                              {modules} m,
                                                              {course_modules} cm,
                                                              {partforum_subscriptions} fs
                                                        WHERE fs.userid = ? AND f.course = ?
                                                              AND fs.partforum = f.id AND cm.instance = f.id
                                                              AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($partforums as $partforum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $partforum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                     partforum_unsubscribe($userid, $partforum->id);
                                 }
                             }
                         }
                     }
                 }
            }
            break;

        case CONTEXT_MODULE:   // Just one partforum
            if (!is_enrolled($context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($partforum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if (!has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                             partforum_unsubscribe($userid, $partforum->id);
                         }
                     }
                 }
            }
            break;
    }

    return true;
}

// Functions to do with read tracking.

/**
 * Remove post tracking for a user in a context
 *
 * @global object
 * @global object
 * @uses CONTEXT_SYSTEM
 * @uses CONTEXT_COURSECAT
 * @uses CONTEXT_COURSE
 * @uses CONTEXT_MODULE
 * @param int $userid
 * @param object $context
 * @return bool
 */
function partforum_remove_user_tracking($userid, $context) {

    global $CFG, $DB;

    if (empty($context->contextlevel)) {
        return false;
    }

    switch ($context->contextlevel) {

        case CONTEXT_SYSTEM:   // For the whole site
            // find all courses in which this user has tracking info
            $allcourses = array();
            if ($courses = $DB->get_records_sql("SELECT c.id
                                                  FROM {course} c,
                                                       {partforum_read} fr,
                                                       {partforum} f
                                                       WHERE c.id = f.course AND f.id = fr.partforumid AND fr.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            if ($courses = $DB->get_records_sql("SELECT c.id
                                              FROM {course} c,
                                                   {partforum_track_prefs} ft,
                                                   {partforum} f
                                             WHERE c.id = f.course AND f.id = ft.partforumid AND ft.userid = ?", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            foreach ($allcourses as $course) {
                $subcontext = context_course::instance($course->id);
                partforum_remove_user_tracking($userid, $subcontext);
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = context_course::instance($course->id);
                     partforum_remove_user_tracking($userid, $subcontext);
                 }
             }
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid), '', 'id')) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     partforum_remove_user_tracking($userid, $subcontext);
                 }
             }
             break;

        case CONTEXT_COURSE:   // For a whole course
            if (!is_enrolled($context, $userid)) {
                 if ($course = $DB->get_record('course', array('id' => $context->instanceid), 'id')) {
                    // find all partforums in which this user has reading tracked
                    if ($partforums = $DB->get_records_sql("SELECT DISTINCT f.id, cm.id as coursemodule
                                                     FROM {partforum} f,
                                                          {modules} m,
                                                          {course_modules} cm,
                                                          {partforum_read} fr
                                                    WHERE fr.userid = ? AND f.course = ?
                                                          AND fr.partforumid = f.id AND cm.instance = f.id
                                                          AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($partforums as $partforum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $partforum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                    partforum_tp_delete_read_records($userid, -1, -1, $partforum->id);
                                 }
                             }
                         }
                     }

                    // find all partforums in which this user has a disabled tracking
                    if ($partforums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                     FROM {partforum} f,
                                                          {modules} m,
                                                          {course_modules} cm,
                                                          {partforum_track_prefs} ft
                                                    WHERE ft.userid = ? AND f.course = ?
                                                          AND ft.partforumid = f.id AND cm.instance = f.id
                                                          AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($partforums as $partforum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $partforum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                    $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'partforumid' => $partforum->id));
                                 }
                             }
                         }
                     }
                 }
            }
            break;

        case CONTEXT_MODULE:   // Just one partforum
            if (!is_enrolled($context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($partforum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if (!has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                            $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'partforumid' => $partforum->id));
                            partforum_tp_delete_read_records($userid, -1, -1, $partforum->id);
                         }
                     }
                 }
            }
            break;
    }

    return true;
}

/**
 * Mark posts as read.
 *
 * @global object
 * @global object
 * @param object $user object
 * @param array $postids array of post ids
 * @return boolean success
 */
function partforum_tp_mark_posts_read($user, $postids) {
    global $CFG, $DB;

    if (!partforum_tp_can_track_partforums(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->partforum_oldpostdays * 24 * 3600);

    if (empty($postids)) {
        return true;

    } else if (count($postids) > 200) {
        while ($part = array_splice($postids, 0, 200)) {
            $status = partforum_tp_mark_posts_read($user, $part) && $status;
        }
        return $status;
    }

    list($usql, $params) = $DB->get_in_or_equal($postids);
    $params[] = $user->id;

    $sql = "SELECT id
              FROM {partforum_read}
             WHERE postid $usql AND userid = ?";
    if ($existing = $DB->get_records_sql($sql, $params)) {
        $existing = array_keys($existing);
    } else {
        $existing = array();
    }

    $new = array_diff($postids, $existing);

    if ($new) {
        list($usql, $new_params) = $DB->get_in_or_equal($new);
        $params = array($user->id, $now, $now, $user->id, $cutoffdate);
        $params = array_merge($params, $new_params);

        $sql = "INSERT INTO {partforum_read} (userid, postid, discussionid, partforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.partforum, ?, ?
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d       ON d.id = p.discussion
                       JOIN {partforum} f                   ON f.id = d.partforum
                       LEFT JOIN {partforum_track_prefs} tf ON (tf.userid = ? AND tf.partforumid = f.id)
                 WHERE p.id $usql
                       AND p.modified >= ?
                       AND (f.trackingtype = ".PARTFORUM_TRACKING_ON."
                            OR (f.trackingtype = ".PARTFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))";
        $status = $DB->execute($sql, $params) && $status;
    }

    if ($existing) {
        list($usql, $new_params) = $DB->get_in_or_equal($existing);
        $params = array($now, $user->id);
        $params = array_merge($params, $new_params);

        $sql = "UPDATE {partforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid $usql";
        $status = $DB->execute($sql, $params) && $status;
    }

    return $status;
}

/**
 * Mark post as read.
 * @global object
 * @global object
 * @param int $userid
 * @param int $postid
 */
function partforum_tp_add_read_record($userid, $postid) {
    global $CFG, $DB;

    $now = time();
    $cutoffdate = $now - ($CFG->partforum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('partforum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {partforum_read} (userid, postid, discussionid, partforumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.partforum, ?, ?
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d ON d.id = p.discussion
                 WHERE p.id = ? AND p.modified >= ?";
        return $DB->execute($sql, array($userid, $now, $now, $postid, $cutoffdate));

    } else {
        $sql = "UPDATE {partforum_read}
                   SET lastread = ?
                 WHERE userid = ? AND postid = ?";
        return $DB->execute($sql, array($now, $userid, $userid));
    }
}

/**
 * Returns all records in the 'partforum_read' table matching the passed keys, indexed
 * by userid.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $partforumid
 * @return array
 */
function partforum_tp_get_read_records($userid=-1, $postid=-1, $discussionid=-1, $partforumid=-1) {
    global $DB;
    $select = '';
    $params = array();

    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($partforumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'partforumid = ?';
        $params[] = $partforumid;
    }

    return $DB->get_records_select('partforum_read', $select, $params);
}

/**
 * Returns all read records for the provided user and discussion, indexed by postid.
 *
 * @global object
 * @param inti $userid
 * @param int $discussionid
 */
function partforum_tp_get_discussion_read_records($userid, $discussionid) {
    global $DB;
    $select = 'userid = ? AND discussionid = ?';
    $fields = 'postid, firstread, lastread';
    return $DB->get_records_select('partforum_read', $select, array($userid, $discussionid), '', $fields);
}

/**
 * If its an old post, do nothing. If the record exists, the maintenance will clear it up later.
 *
 * @return bool
 */
function partforum_tp_mark_post_read($userid, $post, $partforumid) {
    if (!partforum_tp_is_post_old($post)) {
        return partforum_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole partforum as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $partforumid
 * @param int|bool $groupid
 * @return bool
 */
function partforum_tp_mark_partforum_read($user, $partforumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->partforum_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $partforumid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {partforum_posts} p
                   LEFT JOIN {partforum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {partforum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.partforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $groupsel";

    if ($posts = $DB->get_records_sql($sql, $params)) {
        $postids = array_keys($posts);
        return partforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * Marks a whole discussion as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $discussionid
 * @return bool
 */
function partforum_tp_mark_discussion_read($user, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->partforum_oldpostdays*24*60*60);

    $sql = "SELECT p.id
              FROM {partforum_posts} p
                   LEFT JOIN {partforum_read} r ON (r.postid = p.id AND r.userid = ?)
             WHERE p.discussion = ?
                   AND p.modified >= ? AND r.id is NULL";

    if ($posts = $DB->get_records_sql($sql, array($user->id, $discussionid, $cutoffdate))) {
        $postids = array_keys($posts);
        return partforum_tp_mark_posts_read($user, $postids);
    }

    return true;
}

/**
 * @global object
 * @param int $userid
 * @param object $post
 */
function partforum_tp_is_post_read($userid, $post) {
    global $DB;
    return (partforum_tp_is_post_old($post) ||
            $DB->record_exists('partforum_read', array('userid' => $userid, 'postid' => $post->id)));
}

/**
 * @global object
 * @param object $post
 * @param int $time Defautls to time()
 */
function partforum_tp_is_post_old($post, $time=null) {
    global $CFG;

    if (is_null($time)) {
        $time = time();
    }
    return ($post->modified < ($time - ($CFG->partforum_oldpostdays * 24 * 3600)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return bool
 */
function partforum_tp_count_discussion_read_records($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->partforum_oldpostdays) ? (time() - ($CFG->partforum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(DISTINCT p.id) '.
           'FROM {partforum_discussions} d '.
           'LEFT JOIN {partforum_read} r ON d.id = r.discussionid AND r.userid = ? '.
           'LEFT JOIN {partforum_posts} p ON p.discussion = d.id '.
                'AND (p.modified < ? OR p.id = r.postid) '.
           'WHERE d.id = ? ';

    return ($DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid)));
}

/**
 * Returns the count of records for the provided user and discussion.
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $discussionid
 * @return int
 */
function partforum_tp_count_discussion_unread_posts($userid, $discussionid) {
    global $CFG, $DB;

    $cutoffdate = isset($CFG->partforum_oldpostdays) ? (time() - ($CFG->partforum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(p.id) '.
           'FROM {partforum_posts} p '.
           'LEFT JOIN {partforum_read} r ON r.postid = p.id AND r.userid = ? '.
           'WHERE p.discussion = ? '.
                'AND p.modified >= ? AND r.id is NULL';

    return $DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid));
}

/**
 * Returns the count of posts for the provided partforum and [optionally] group.
 * @global object
 * @global object
 * @param int $partforumid
 * @param int|bool $groupid
 * @return int
 */
function partforum_tp_count_partforum_posts($partforumid, $groupid=false) {
    global $CFG, $DB;
    $params = array($partforumid);
    $sql = 'SELECT COUNT(*) '.
           'FROM {partforum_posts} fp,{partforum_discussions} fd '.
           'WHERE fd.partforum = ? AND fp.discussion = fd.id';
    if ($groupid !== false) {
        $sql .= ' AND (fd.groupid = ? OR fd.groupid = -1)';
        $params[] = $groupid;
    }
    $count = $DB->count_records_sql($sql, $params);


    return $count;
}

/**
 * Returns the count of records for the provided user and partforum and [optionally] group.
 * @global object
 * @global object
 * @param int $userid
 * @param int $partforumid
 * @param int|bool $groupid
 * @return int
 */
function partforum_tp_count_partforum_read_records($userid, $partforumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->partforum_oldpostdays*24*60*60);

    $groupsel = '';
    $params = array($userid, $partforumid, $cutoffdate);
    if ($groupid !== false) {
        $groupsel = "AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT COUNT(p.id)
              FROM  {partforum_posts} p
                    JOIN {partforum_discussions} d ON d.id = p.discussion
                    LEFT JOIN {partforum_read} r   ON (r.postid = p.id AND r.userid= ?)
              WHERE d.partforum = ?
                    AND (p.modified < $cutoffdate OR (p.modified >= ? AND r.id IS NOT NULL))
                    $groupsel";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns the count of records for the provided user and course.
 * Please note that group access is ignored!
 *
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid
 * @return array
 */
function partforum_tp_get_course_unread_posts($userid, $courseid) {
    global $CFG, $DB;

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->partforum_oldpostdays*24*60*60);
    $params = array($userid, $userid, $courseid, $cutoffdate);

    if (!empty($CFG->partforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {partforum_posts} p
                   JOIN {partforum_discussions} d       ON d.id = p.discussion
                   JOIN {partforum} f                   ON f.id = d.partforum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {partforum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {partforum_track_prefs} tf ON (tf.userid = ? AND tf.partforumid = f.id)
             WHERE f.course = ?
                   AND p.modified >= ? AND r.id is NULL
                   AND (f.trackingtype = ".PARTFORUM_TRACKING_ON."
                        OR (f.trackingtype = ".PARTFORUM_TRACKING_OPTIONAL." AND tf.id IS NULL))
                   $timedsql
          GROUP BY f.id";

    if ($return = $DB->get_records_sql($sql, $params)) {
        return $return;
    }

    return array();
}

/**
 * Returns the count of records for the provided user and partforum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function partforum_tp_count_partforum_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $partforumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = partforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$partforumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$partforumid];
    }

    if (has_capability('moodle/site:accessallgroups', context_module::instance($cm->id))) {
        return $readcache[$course->id][$partforumid];
    }

    require_once($CFG->dirroot.'/course/lib.php');

    $modinfo =& get_fast_modinfo($course);
    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
    }

    $mygroups = $modinfo->groups[$cm->groupingid];

    // add all groups posts
    if (empty($mygroups)) {
        $mygroups = array(-1=>-1);
    } else {
        $mygroups[-1] = -1;
    }

    list ($groups_sql, $groups_params) = $DB->get_in_or_equal($mygroups);

    $now = round(time(), -2); // db cache friendliness
    $cutoffdate = $now - ($CFG->partforum_oldpostdays*24*60*60);
    $params = array($USER->id, $partforumid, $cutoffdate);

    if (!empty($CFG->partforum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $params = array_merge($params, $groups_params);

    $sql = "SELECT COUNT(p.id)
              FROM {partforum_posts} p
                   JOIN {partforum_discussions} d ON p.discussion = d.id
                   LEFT JOIN {partforum_read} r   ON (r.postid = p.id AND r.userid = ?)
             WHERE d.partforum = ?
                   AND p.modified >= ? AND r.id is NULL
                   $timedsql
                   AND d.groupid $groups_sql";

    return $DB->get_field_sql($sql, $params);
}

/**
 * Deletes read records for the specified index. At least one parameter must be specified.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $partforumid
 * @return bool
 */
function partforum_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $partforumid=-1) {
    global $DB;
    $params = array();

    $select = '';
    if ($userid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'userid = ?';
        $params[] = $userid;
    }
    if ($postid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'postid = ?';
        $params[] = $postid;
    }
    if ($discussionid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'discussionid = ?';
        $params[] = $discussionid;
    }
    if ($partforumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'partforumid = ?';
        $params[] = $partforumid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('partforum_read', $select, $params);
    }
}
/**
 * Get a list of partforums not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by partforum id, or false.
 */
function partforum_tp_get_untracked_partforums($userid, $courseid) {
    global $CFG, $DB;

    $sql = "SELECT f.id
              FROM {partforum} f
                   LEFT JOIN {partforum_track_prefs} ft ON (ft.partforumid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   AND (f.trackingtype = ".PARTFORUM_TRACKING_OFF."
                        OR (f.trackingtype = ".PARTFORUM_TRACKING_OPTIONAL." AND ft.id IS NOT NULL))";

    if ($partforums = $DB->get_records_sql($sql, array($userid, $courseid))) {
        foreach ($partforums as $partforum) {
            $partforums[$partforum->id] = $partforum;
        }
        return $partforums;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track partforums and optionally a particular partforum.
 * Checks the site settings, the user settings and the partforum settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $partforum The partforum object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function partforum_tp_can_track_partforums($partforum=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->partforum_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if ($partforum === false) {
        // general abitily to track partforums
        return (bool)$user->trackpartforums;
    }


    // Work toward always passing an object...
    if (is_numeric($partforum)) {
        debugging('Better use proper partforum object.', DEBUG_DEVELOPER);
        $partforum = $DB->get_record('partforum', array('id' => $partforum), '', 'id,trackingtype');
    }

    $partforumallows = ($partforum->trackingtype == PARTFORUM_TRACKING_OPTIONAL);
    $partforumforced = ($partforum->trackingtype == PARTFORUM_TRACKING_ON);

    return ($partforumforced || $partforumallows)  && !empty($user->trackpartforums);
}

/**
 * Tells whether a specific partforum is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $partforum If int, the id of the partforum being checked; if object, the partforum object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function partforum_tp_is_tracked($partforum, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($partforum)) {
        debugging('Better use proper partforum object.', DEBUG_DEVELOPER);
        $partforum = $DB->get_record('partforum', array('id' => $partforum));
    }

    if (!partforum_tp_can_track_partforums($partforum, $user)) {
        return false;
    }

    $partforumallows = ($partforum->trackingtype == PARTFORUM_TRACKING_OPTIONAL);
    $partforumforced = ($partforum->trackingtype == PARTFORUM_TRACKING_ON);

    return $partforumforced ||
           ($partforumallows && $DB->get_record('partforum_track_prefs', array('userid' => $user->id, 'partforumid' => $partforum->id)) === false);
}

/**
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $userid
 */
function partforum_tp_start_tracking($partforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'partforumid' => $partforumid));
}

/**
 * @global object
 * @global object
 * @param int $partforumid
 * @param int $userid
 */
function partforum_tp_stop_tracking($partforumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('partforum_track_prefs', array('userid' => $userid, 'partforumid' => $partforumid))) {
        $track_prefs = new stdClass();
        $track_prefs->userid = $userid;
        $track_prefs->partforumid = $partforumid;
        $DB->insert_record('partforum_track_prefs', $track_prefs);
    }

    return partforum_tp_delete_read_records($userid, -1, -1, $partforumid);
}


/**
 * Clean old records from the partforum_read table.
 * @global object
 * @global object
 * @return void
 */
function partforum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->partforum_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the partforum_read table.
    $cutoffdate = time() - ($CFG->partforum_oldpostdays*24*60*60);

    //first get the oldest tracking present - we need tis to speedup the next delete query
    $sql = "SELECT MIN(fp.modified) AS first
              FROM {partforum_posts} fp
                   JOIN {partforum_read} fr ON fr.postid=fp.id";
    if (!$first = $DB->get_field_sql($sql)) {
        // nothing to delete;
        return;
    }

    // now delete old tracking info
    $sql = "DELETE
              FROM {partforum_read}
             WHERE postid IN (SELECT fp.id
                                FROM {partforum_posts} fp
                               WHERE fp.modified >= ? AND fp.modified < ?)";
    $DB->execute($sql, array($first, $cutoffdate));
}

/**
 * Sets the last post for a given discussion
 *
 * @global object
 * @global object
 * @param into $discussionid
 * @return bool|int
 **/
function partforum_discussion_update_last_post($discussionid) {
    global $CFG, $DB;

// Check the given discussion exists
    if (!$DB->record_exists('partforum_discussions', array('id' => $discussionid))) {
        return false;
    }

// Use SQL to find the last post for this discussion
    $sql = "SELECT id, userid, modified
              FROM {partforum_posts}
             WHERE discussion=?
             ORDER BY modified DESC";

// Lets go find the last post
    if (($lastposts = $DB->get_records_sql($sql, array($discussionid), 0, 1))) {
        $lastpost = reset($lastposts);
        $discussionobject = new stdClass();
        $discussionobject->id           = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;
        $DB->update_record('partforum_discussions', $discussionobject);
        return $lastpost->id;
    }

// To get here either we couldn't find a post for the discussion (weird)
// or we couldn't update the discussion record (weird x2)
    return false;
}


/**
 * @return array
 */
function partforum_get_view_actions() {
    return array('view discussion', 'search', 'partforum', 'partforums', 'subscribers', 'view partforum');
}

/**
 * @return array
 */
function partforum_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * this function returns all the separate partforum ids, given a courseid
 *
 * @global object
 * @global object
 * @param int $courseid
 * @return array
 */
function partforum_get_separate_modules($courseid) {

    global $CFG,$DB;
    $partforummodule = $DB->get_record("modules", array("name" => "partforum"));

    $sql = 'SELECT f.id, f.id FROM {partforum} f, {course_modules} cm WHERE
           f.id = cm.instance AND cm.module =? AND cm.visible = 1 AND cm.course = ?
           AND cm.groupmode ='.SEPARATEGROUPS;

    return $DB->get_records_sql($sql, array($partforummodule->id, $courseid));

}

/**
 * @global object
 * @global object
 * @global object
 * @param object $partforum
 * @param object $cm
 * @return bool
 */
function partforum_check_throttling($partforum, $cm=null) {
    global $USER, $CFG, $DB, $OUTPUT;

    if (is_numeric($partforum)) {
        $partforum = $DB->get_record('partforum',array('id'=>$partforum));
    }
    if (!is_object($partforum)) {
        return false;  // this is broken.
    }

    if (empty($partforum->blockafter)) {
        return true;
    }

    if (empty($partforum->blockperiod)) {
        return true;
    }

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id, $partforum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    $modcontext = context_module::instance($cm->id);
    if(has_capability('mod/partforum:postwithoutthrottling', $modcontext)) {
        return true;
    }

    // get the number of posts in the last period we care about
    $timenow = time();
    $timeafter = $timenow - $partforum->blockperiod;

    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {partforum_posts} p'
                                      .' JOIN {partforum_discussions} d'
                                      .' ON p.discussion = d.id WHERE d.partforum = ?'
                                      .' AND p.userid = ? AND p.created > ?', array($partforum->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $partforum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$partforum->blockperiod);

    if ($partforum->blockafter <= $numposts) {
        print_error('partforumblockingtoomanyposts', 'error', $CFG->wwwroot.'/mod/partforum/view.php?f='.$partforum->id, $a);
    }
    if ($partforum->warnafter <= $numposts) {
        echo $OUTPUT->notification(get_string('partforumblockingalmosttoomanyposts','partforum',$a));
    }


}


/**
 * Removes all grades from gradebook
 *
 * @global object
 * @global object
 * @param int $courseid
 * @param string $type optional
 */
function partforum_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $wheresql = '';
    $params = array($courseid);
    if ($type) {
        $wheresql = "AND f.type=?";
        $params[] = $type;
    }

    $sql = "SELECT f.*, cm.idnumber as cmidnumber, f.course as courseid
              FROM {partforum} f, {course_modules} cm, {modules} m
             WHERE m.name='partforum' AND m.id=cm.module AND cm.instance=f.id AND f.course=? $wheresql";

    if ($partforums = $DB->get_records_sql($sql, $params)) {
        foreach ($partforums as $partforum) {
            partforum_grade_item_update($partforum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified partforum
 * and clean up any related data.
 *
 * @global object
 * @global object
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function partforum_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->dirroot.'/rating/lib.php');

    $componentstr = get_string('modulenameplural', 'partforum');
    $status = array();

    $params = array($data->courseid);

    $removeposts = false;
    $typesql     = "";
    if (!empty($data->reset_partforum_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetpartforumsall', 'partforum');
        $types       = array();
    } else if (!empty($data->reset_partforum_types)){
        $removeposts = true;
        $typesql     = "";
        $types       = array();
        $partforum_types_all = partforum_get_partforum_types_all();
        foreach ($data->reset_partforum_types as $type) {
            if (!array_key_exists($type, $partforum_types_all)) {
                continue;
            }
            $typesql .= " AND f.type=?";
            $types[] = $partforum_types_all[$type];
            $params[] = $type;
        }
        $typesstr = get_string('resetpartforums', 'partforum').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {partforum_discussions} fd, {partforum} f
                           WHERE f.course=? AND f.id=fd.partforum";

    $allpartforumssql      = "SELECT f.id
                            FROM {partforum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {partforum_posts} fp, {partforum_discussions} fd, {partforum} f
                           WHERE f.course=? AND f.id=fd.partforum AND fd.id=fp.discussion";

    $partforumssql = $partforums = $rm = null;

    if( $removeposts || !empty($data->reset_partforum_ratings) ) {
        $partforumssql      = "$allpartforumssql $typesql";
        $partforums = $partforums = $DB->get_records_sql($partforumssql, $params);
        $rm = new rating_manager();;
        $ratingdeloptions = new stdClass;
        $ratingdeloptions->component = 'mod_partforum';
        $ratingdeloptions->ratingarea = 'post';
    }

    if ($removeposts) {
        $discussionssql = "$alldiscussionssql $typesql";
        $postssql       = "$allpostssql $typesql";

        // now get rid of all attachments
        $fs = get_file_storage();
        if ($partforums) {
            foreach ($partforums as $partforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('partforum', $partforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);
                $fs->delete_area_files($context->id, 'mod_partforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_partforum', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('partforum_read', "partforumid IN ($partforumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('partforum_track_prefs', "partforumid IN ($partforumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('partforum_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion partforums
        $DB->delete_records_select('partforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('partforum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple partforums
        $DB->delete_records_select('partforum_discussions', "partforum IN ($partforumssql AND f.type <> 'single')", $params);

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            if (empty($types)) {
                partforum_reset_gradebook($data->courseid);
            } else {
                foreach ($types as $type) {
                    partforum_reset_gradebook($data->courseid, $type);
                }
            }
        }

        $status[] = array('component'=>$componentstr, 'item'=>$typesstr, 'error'=>false);
    }

    // remove all ratings in this course's partforums
    if (!empty($data->reset_partforum_ratings)) {
        if ($partforums) {
            foreach ($partforums as $partforumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('partforum', $partforumid)) {
                    continue;
                }
                $context = context_module::instance($cm->id);

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // remove all grades from gradebook
        if (empty($data->reset_gradebook_grades)) {
            partforum_reset_gradebook($data->courseid);
        }
    }

    // remove all subscriptions unconditionally - even for users still enrolled in course
    if (!empty($data->reset_partforum_subscriptions)) {
        $DB->delete_records_select('partforum_subscriptions', "partforum IN ($allpartforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','partforum'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_partforum_track_prefs)) {
        $DB->delete_records_select('partforum_track_prefs', "partforumid IN ($allpartforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resettrackprefs','partforum'), 'error'=>false);
    }

    /// updating dates - shift may be negative too
    if ($data->timeshift) {
        shift_course_mod_dates('partforum', array('assesstimestart', 'assesstimefinish'), $data->timeshift, $data->courseid);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('datechanged'), 'error'=>false);
    }

    return $status;
}

/**
 * Called by course/reset.php
 *
 * @param $mform form passed by reference
 */
function partforum_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'partforumheader', get_string('modulenameplural', 'partforum'));

    $mform->addElement('checkbox', 'reset_partforum_all', get_string('resetpartforumsall','partforum'));

    $mform->addElement('select', 'reset_partforum_types', get_string('resetpartforums', 'partforum'), partforum_get_partforum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_partforum_types');
    $mform->disabledIf('reset_partforum_types', 'reset_partforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_partforum_subscriptions', get_string('resetsubscriptions','partforum'));
    $mform->setAdvanced('reset_partforum_subscriptions');

    $mform->addElement('checkbox', 'reset_partforum_track_prefs', get_string('resettrackprefs','partforum'));
    $mform->setAdvanced('reset_partforum_track_prefs');
    $mform->disabledIf('reset_partforum_track_prefs', 'reset_partforum_all', 'checked');

    $mform->addElement('checkbox', 'reset_partforum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_partforum_ratings', 'reset_partforum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function partforum_reset_course_form_defaults($course) {
    return array('reset_partforum_all'=>1, 'reset_partforum_subscriptions'=>0, 'reset_partforum_track_prefs'=>0, 'reset_partforum_ratings'=>1);
}

/**
 * Converts a partforum to use the Roles System
 *
 * @global object
 * @global object
 * @param object $partforum        a partforum object with the same attributes as a record
 *                        from the partforum database table
 * @param int $partforummodid   the id of the partforum module, from the modules table
 * @param array $teacherroles array of roles that have archetype teacher
 * @param array $studentroles array of roles that have archetype student
 * @param array $guestroles   array of roles that have archetype guest
 * @param int $cmid         the course_module id for this partforum instance
 * @return boolean      partforum was converted or not
 */
function partforum_convert_to_roles($partforum, $partforummodid, $teacherroles=array(),
                                $studentroles=array(), $guestroles=array(), $cmid=NULL) {

    global $CFG, $DB, $OUTPUT;

    if (!isset($partforum->open) && !isset($partforum->assesspublic)) {
        // We assume that this partforum has already been converted to use the
        // Roles System. Columns partforum.open and partforum.assesspublic get dropped
        // once the partforum module has been upgraded to use Roles.
        return false;
    }

    if ($partforum->type == 'teacher') {

        // Teacher partforums should be converted to normal partforums that
        // use the Roles System to implement the old behavior.
        // Note:
        //   Seems that teacher partforums were never backed up in 1.6 since they
        //   didn't have an entry in the course_modules table.
        require_once($CFG->dirroot.'/course/lib.php');

        if ($DB->count_records('partforum_discussions', array('partforum' => $partforum->id)) == 0) {
            // Delete empty teacher partforums.
            $DB->delete_records('partforum', array('id' => $partforum->id));
        } else {
            // Create a course module for the partforum and assign it to
            // section 0 in the course.
            $mod = new stdClass();
            $mod->course = $partforum->course;
            $mod->module = $partforummodid;
            $mod->instance = $partforum->id;
            $mod->section = 0;
            $mod->visible = 0;     // Hide the partforum
            $mod->visibleold = 0;  // Hide the partforum
            $mod->groupmode = 0;

            if (!$cmid = add_course_module($mod)) {
                print_error('cannotcreateinstanceforteacher', 'partforum');
            } else {
                $mod->coursemodule = $cmid;
                if (!$sectionid = add_mod_to_section($mod)) {
                    print_error('cannotaddteacherpartforumto', 'partforum');
                } else {
                    $DB->set_field('course_modules', 'section', $sectionid, array('id' => $cmid));
                }
            }

            // Change the partforum type to general.
            $partforum->type = 'general';
            $DB->update_record('partforum', $partforum);

            $context =context_module::instance($cmid);

            // Create overrides for default student and guest roles (prevent).
            foreach ($studentroles as $studentrole) {
                assign_capability('mod/partforum:viewdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:viewhiddentimedposts', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:viewrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:createattachment', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:deleteownpost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:deleteanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:splitdiscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:movediscussions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:editanypost', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:viewqandawithoutposting', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:viewsubscribers', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:managesubscriptions', CAP_PREVENT, $studentrole->id, $context->id);
                assign_capability('mod/partforum:postwithoutthrottling', CAP_PREVENT, $studentrole->id, $context->id);
            }
            foreach ($guestroles as $guestrole) {
                assign_capability('mod/partforum:viewdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:viewhiddentimedposts', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:startdiscussion', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:replypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:viewrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:viewanyrating', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:rate', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:createattachment', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:deleteownpost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:deleteanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:splitdiscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:movediscussions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:editanypost', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:viewqandawithoutposting', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:viewsubscribers', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:managesubscriptions', CAP_PREVENT, $guestrole->id, $context->id);
                assign_capability('mod/partforum:postwithoutthrottling', CAP_PREVENT, $guestrole->id, $context->id);
            }
        }
    } else {
        // Non-teacher partforum.

        if (empty($cmid)) {
            // We were not given the course_module id. Try to find it.
            if (!$cm = get_coursemodule_from_instance('partforum', $partforum->id)) {
                echo $OUTPUT->notification('Could not get the course module for the partforum');
                return false;
            } else {
                $cmid = $cm->id;
            }
        }
        $context =context_module::instance($cmid);

        // $partforum->open defines what students can do:
        //   0 = No discussions, no replies
        //   1 = No discussions, but replies are allowed
        //   2 = Discussions and replies are allowed
        switch ($partforum->open) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/partforum:replypost', CAP_PREVENT, $studentrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:startdiscussion', CAP_PREVENT, $studentrole->id, $context->id);
                    assign_capability('mod/partforum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:startdiscussion', CAP_ALLOW, $studentrole->id, $context->id);
                    assign_capability('mod/partforum:replypost', CAP_ALLOW, $studentrole->id, $context->id);
                }
                break;
        }

        // $partforum->assessed defines whether partforum rating is turned
        // on (1 or 2) and who can rate posts:
        //   1 = Everyone can rate posts
        //   2 = Only teachers can rate posts
        switch ($partforum->assessed) {
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:rate', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/partforum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:rate', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/partforum:rate', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        // $partforum->assesspublic defines whether students can see
        // everybody's ratings:
        //   0 = Students can only see their own ratings
        //   1 = Students can see everyone's ratings
        switch ($partforum->assesspublic) {
            case 0:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:viewanyrating', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/partforum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('mod/partforum:viewanyrating', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('mod/partforum:viewanyrating', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }

        if (empty($cm)) {
            $cm = $DB->get_record('course_modules', array('id' => $cmid));
        }

        // $cm->groupmode:
        // 0 - No groups
        // 1 - Separate groups
        // 2 - Visible groups
        switch ($cm->groupmode) {
            case 0:
                break;
            case 1:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_PREVENT, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
            case 2:
                foreach ($studentroles as $studentrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $studentrole->id, $context->id);
                }
                foreach ($teacherroles as $teacherrole) {
                    assign_capability('moodle/site:accessallgroups', CAP_ALLOW, $teacherrole->id, $context->id);
                }
                break;
        }
    }
    return true;
}

/**
 * Returns array of partforum layout modes
 *
 * @return array
 */
function partforum_get_layout_modes() {
    return array (PARTFORUM_MODE_FLATOLDEST => get_string('modeflatoldestfirst', 'partforum'),
                  PARTFORUM_MODE_FLATNEWEST => get_string('modeflatnewestfirst', 'partforum'),
                  PARTFORUM_MODE_THREADED   => get_string('modethreaded', 'partforum'),
                  PARTFORUM_MODE_NESTED     => get_string('modenested', 'partforum'));
}

/**
 * Returns array of partforum types chooseable on the partforum editing form
 *
 * @return array
 */
function partforum_get_partforum_types() {
    return array ('general'  => get_string('generalpartforum', 'partforum'),
    		 	  'participation'   => get_string('partforum', 'partforum'),
                  'eachuser' => get_string('eachuserpartforum', 'partforum'),
                  'single'   => get_string('singlepartforum', 'partforum'),
                  'qanda'    => get_string('qandapartforum', 'partforum'),
                  'blog'     => get_string('blogpartforum', 'partforum'));
}

/**
 * Returns array of all partforum layout modes
 *
 * @return array
 */
function partforum_get_partforum_types_all() {
    return array ('news'     => get_string('namenews','partforum'),
                  'social'   => get_string('namesocial','partforum'),
                  'general'  => get_string('generalpartforum', 'partforum'),
                  'participation'   => get_string('partforum', 'partforum'),
                  'eachuser' => get_string('eachuserpartforum', 'partforum'),
                  'single'   => get_string('singlepartforum', 'partforum'),
                  'qanda'    => get_string('qandapartforum', 'partforum'),
                  'blog'     => get_string('blogpartforum', 'partforum'));
}

/**
 * Returns array of partforum open modes
 *
 * @return array
 */
function partforum_get_open_modes() {
    return array ('2' => get_string('openmode2', 'partforum'),
                  '1' => get_string('openmode1', 'partforum'),
                  '0' => get_string('openmode0', 'partforum') );
}

/**
 * Returns all other caps used in module
 *
 * @return array
 */
function partforum_get_extra_capabilities() {
    return array('moodle/site:accessallgroups', 'moodle/site:viewfullnames', 'moodle/site:trustcontent', 'moodle/rating:view', 'moodle/rating:viewany', 'moodle/rating:viewall', 'moodle/rating:rate');
}


/**
 * This function is used to extend the global navigation by add partforum nodes if there
 * is relevant content.
 *
 * @param navigation_node $navref
 * @param stdClass $course
 * @param stdClass $module
 * @param stdClass $cm
 */
/*************************************************
function partforum_extend_navigation($navref, $course, $module, $cm) {
    global $CFG, $OUTPUT, $USER;

    $limit = 5;

    $discussions = partforum_get_discussions($cm,"d.timemodified DESC", false, -1, $limit);
    $discussioncount = partforum_get_discussions_count($cm);
    if (!is_array($discussions) || count($discussions)==0) {
        return;
    }
    $discussionnode = $navref->add(get_string('discussions', 'partforum').' ('.$discussioncount.')');
    $discussionnode->mainnavonly = true;
    $discussionnode->display = false; // Do not display on navigation (only on navbar)

    foreach ($discussions as $discussion) {
        $icon = new pix_icon('i/feedback', '');
        $url = new moodle_url('/mod/partforum/discuss.php', array('d'=>$discussion->discussion));
        $discussionnode->add($discussion->subject, $url, navigation_node::TYPE_SETTING, null, null, $icon);
    }

    if ($discussioncount > count($discussions)) {
        if (!empty($navref->action)) {
            $url = $navref->action;
        } else {
            $url = new moodle_url('/mod/partforum/view.php', array('id'=>$cm->id));
        }
        $discussionnode->add(get_string('viewalldiscussions', 'partforum'), $url, navigation_node::TYPE_SETTING, null, null, $icon);
    }

    $index = 0;
    $recentposts = array();
    $lastlogin = time() - COURSE_MAX_RECENT_PERIOD;
    if (!isguestuser() and !empty($USER->lastcourseaccess[$course->id])) {
        if ($USER->lastcourseaccess[$course->id] > $lastlogin) {
            $lastlogin = $USER->lastcourseaccess[$course->id];
        }
    }
    partforum_get_recent_mod_activity($recentposts, $index, $lastlogin, $course->id, $cm->id);

    if (is_array($recentposts) && count($recentposts)>0) {
        $recentnode = $navref->add(get_string('recentactivity').' ('.count($recentposts).')');
        $recentnode->mainnavonly = true;
        $recentnode->display = false;
        foreach ($recentposts as $post) {
            $icon = new pix_icon('i/feedback', '');
            $url = new moodle_url('/mod/partforum/discuss.php', array('d'=>$post->content->discussion));
            $title = $post->content->subject."\n".userdate($post->timestamp, get_string('strftimerecent', 'langconfig'))."\n".$post->user->firstname.' '.$post->user->lastname;
            $recentnode->add($title, $url, navigation_node::TYPE_SETTING, null, null, $icon);
        }
    }
}
*************************/

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settings The settings navigation object
 * @param navigation_node $partforumnode The node to add module settings to
 */
function partforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $partforumnode) {
    global $USER, $PAGE, $CFG, $DB, $OUTPUT;

    $partforumobject = $DB->get_record("partforum", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = get_context_instance(CONTEXT_MODULE, $PAGE->cm->instance);
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/partforum:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = partforum_get_forcesubscribed($partforumobject);
    $cansubscribe = ($activeenrolled && $subscriptionmode != PARTFORUM_FORCESUBSCRIBE && ($subscriptionmode != PARTFORUM_DISALLOWSUBSCRIBE || $canmanage));

    if ($canmanage) {
        $mode = $partforumnode->add(get_string('subscriptionmode', 'partforum'), null, navigation_node::TYPE_CONTAINER);

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'partforum'), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$partforumobject->id, 'mode'=>PARTFORUM_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "partforum"), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$partforumobject->id, 'mode'=>PARTFORUM_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "partforum"), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$partforumobject->id, 'mode'=>PARTFORUM_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'partforum'), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$partforumobject->id, 'mode'=>PARTFORUM_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

        switch ($subscriptionmode) {
            case PARTFORUM_CHOOSESUBSCRIBE : // 0
                $allowchoice->action = null;
                $allowchoice->add_class('activesetting');
                break;
            case PARTFORUM_FORCESUBSCRIBE : // 1
                $forceforever->action = null;
                $forceforever->add_class('activesetting');
                break;
            case PARTFORUM_INITIALSUBSCRIBE : // 2
                $forceinitially->action = null;
                $forceinitially->add_class('activesetting');
                break;
            case PARTFORUM_DISALLOWSUBSCRIBE : // 3
                $disallowchoice->action = null;
                $disallowchoice->add_class('activesetting');
                break;
        }

    } else if ($activeenrolled) {

        switch ($subscriptionmode) {
            case PARTFORUM_CHOOSESUBSCRIBE : // 0
                $notenode = $partforumnode->add(get_string('subscriptionoptional', 'partforum'));
                break;
            case PARTFORUM_FORCESUBSCRIBE : // 1
                $notenode = $partforumnode->add(get_string('subscriptionforced', 'partforum'));
                break;
            case PARTFORUM_INITIALSUBSCRIBE : // 2
                $notenode = $partforumnode->add(get_string('subscriptionauto', 'partforum'));
                break;
            case PARTFORUM_DISALLOWSUBSCRIBE : // 3
                $notenode = $partforumnode->add(get_string('subscriptiondisabled', 'partforum'));
                break;
        }
    }

    if ($cansubscribe) {
        if (partforum_is_subscribed($USER->id, $partforumobject)) {
            $linktext = get_string('unsubscribe', 'partforum');
        } else {
            $linktext = get_string('subscribe', 'partforum');
        }
        $url = new moodle_url('/mod/partforum/subscribe.php', array('id'=>$partforumobject->id, 'sesskey'=>sesskey()));
        $partforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    if (has_capability('mod/partforum:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/partforum/subscribers.php', array('id'=>$partforumobject->id));
        $partforumnode->add(get_string('showsubscribers', 'partforum'), $url, navigation_node::TYPE_SETTING);
    }

    if ($enrolled && partforum_tp_can_track_partforums($partforumobject)) { // keep tracking info for users with suspended enrolments
        if ($partforumobject->trackingtype != PARTFORUM_TRACKING_OPTIONAL) {
            //tracking forced on or off in partforum settings so dont provide a link here to change it
            //could add unclickable text like for forced subscription but not sure this justifies adding another menu item
        } else {
            if (partforum_tp_is_tracked($partforumobject)) {
                $linktext = get_string('notrackpartforum', 'partforum');
            } else {
                $linktext = get_string('trackpartforum', 'partforum');
            }
            $url = new moodle_url('/mod/partforum/settracking.php', array('id'=>$partforumobject->id));
            $partforumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if ($enrolled && !empty($CFG->enablerssfeeds) && !empty($CFG->partforum_enablerssfeeds) && $partforumobject->rsstype && $partforumobject->rssarticles) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($partforumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','partforum');
        } else {
            $string = get_string('rsssubscriberssposts','partforum');
        }
        if (!isloggedin()) {
            $userid = 0;
        } else {
            $userid = $USER->id;
        }
        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_partforum", $partforumobject->id));
        $partforumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }
}

/**
 * Abstract class used by partforum subscriber selection controls
 * @package mod-partforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class partforum_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the partforum this selector is being used for
     * @var int
     */
    protected $partforumid = null;
    /**
     * The context of the partforum this selector is being used for
     * @var object
     */
    protected $context = null;
    /**
     * The id of the current group
     * @var int
     */
    protected $currentgroup = null;

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['context'])) {
            $this->context = $options['context'];
        }
        if (isset($options['currentgroup'])) {
            $this->currentgroup = $options['currentgroup'];
        }
        if (isset($options['partforumid'])) {
            $this->partforumid = $options['partforumid'];
        }
    }

    /**
     * Returns an array of options to seralise and store for searches
     *
     * @return array
     */
    protected function get_options() {
        global $CFG;
        $options = parent::get_options();
        $options['file'] =  substr(__FILE__, strlen($CFG->dirroot.'/'));
        $options['context'] = $this->context;
        $options['currentgroup'] = $this->currentgroup;
        $options['partforumid'] = $this->partforumid;
        return $options;
    }

}

/**
 * A user selector control for potential subscribers to the selected partforum
 * @package mod-partforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partforum_potential_subscriber_selector extends partforum_subscriber_selector_base {

    /**
     * If set to true EVERYONE in this course is force subscribed to this partforum
     * @var bool
     */
    protected $forcesubscribed = false;
    /**
     * Can be used to store existing subscribers so that they can be removed from
     * the potential subscribers list
     */
    protected $existingsubscribers = array();

    /**
     * Constructor method
     * @param string $name
     * @param array $options
     */
    public function __construct($name, $options) {
        parent::__construct($name, $options);
        if (isset($options['forcesubscribed'])) {
            $this->forcesubscribed=true;
        }
    }

    /**
     * Returns an arary of options for this control
     * @return array
     */
    protected function get_options() {
        $options = parent::get_options();
        if ($this->forcesubscribed===true) {
            $options['forcesubscribed']=1;
        }
        return $options;
    }

    /**
     * Finds all potential users
     *
     * Potential users are determined by checking for users with a capability
     * determined in {@see partforum_get_potential_subscribers()}
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;

        $availableusers = partforum_get_potential_subscribers($this->context, $this->currentgroup, $this->required_fields_sql('u'), 'u.firstname ASC, u.lastname ASC');

        if (empty($availableusers)) {
            $availableusers = array();
        } else if ($search) {
            $search = strtolower($search);
            foreach ($availableusers as $key=>$user) {
                if (stripos($user->firstname, $search) === false && stripos($user->lastname, $search) === false) {
                    unset($availableusers[$key]);
                }
            }
        }

        // Unset any existing subscribers
        if (count($this->existingsubscribers)>0 && !$this->forcesubscribed) {
            foreach ($this->existingsubscribers as $group) {
                foreach ($group as $user) {
                    if (array_key_exists($user->id, $availableusers)) {
                        unset($availableusers[$user->id]);
                    }
                }
            }
        }

        if ($this->forcesubscribed) {
            return array(get_string("existingsubscribers", 'partforum') => $availableusers);
        } else {
            return array(get_string("potentialsubscribers", 'partforum') => $availableusers);
        }
    }

    /**
     * Sets the existing subscribers
     * @param array $users
     */
    public function set_existing_subscribers(array $users) {
        $this->existingsubscribers = $users;
    }

    /**
     * Sets this partforum as force subscribed or not
     */
    public function set_force_subscribed($setting=true) {
        $this->forcesubscribed = true;
    }
}

/**
 * User selector control for removing subscribed users
 * @package mod-partforum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partforum_existing_subscriber_selector extends partforum_subscriber_selector_base {

    /**
     * Finds all subscribed users
     *
     * @param string $search
     * @return array
     */
    public function find_users($search) {
        global $DB;
        list($wherecondition, $params) = $this->search_sql($search, 'u');
        $params['partforumid'] = $this->partforumid;

        // only active enrolled or everybody on the frontpage
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields = $this->required_fields_sql('u');

        $subscribers = $DB->get_records_sql("SELECT $fields
                                               FROM {user} u
                                               JOIN ($esql) je ON je.id = u.id
                                               JOIN {partforum_subscriptions} s ON s.userid = u.id
                                              WHERE $wherecondition AND s.partforum = :partforumid
                                           ORDER BY u.lastname ASC, u.firstname ASC", $params);

        return array(get_string("existingsubscribers", 'partforum') => $subscribers);
    }

}

/**
 * Adds information about unread messages, that is only required for the course view page (and
 * similar), to the course-module object.
 * @param cm_info $cm Course-module object
 */
function partforum_cm_info_view(cm_info $cm) {
    global $CFG;

    // Get tracking status (once per request)
    static $initialised;
    static $usetracking, $strunreadpostsone;
    if (!isset($initialised)) {
        if ($usetracking = partforum_tp_can_track_partforums()) {
            $strunreadpostsone = get_string('unreadpostsone', 'partforum');
        }
        $initialised = true;
    }

    if ($usetracking) {
        if ($unread = partforum_tp_count_partforum_unread_posts($cm, $cm->get_course())) {
            $out = '<span class="unread"> <a href="' . $cm->get_url() . '">';
            if ($unread == 1) {
                $out .= $strunreadpostsone;
            } else {
                $out .= get_string('unreadpostsnumber', 'partforum', $unread);
            }
            $out .= '</a></span>';
            $cm->set_after_link($out);
        }
    }
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function partforum_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $partforum_pagetype = array(
        'mod-partforum-*'=>get_string('page-mod-partforum-x', 'partforum'),
        'mod-partforum-view'=>get_string('page-mod-partforum-view', 'partforum'),
        'mod-partforum-discuss'=>get_string('page-mod-partforum-discuss', 'partforum')
    );
    return $partforum_pagetype;
}



/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $partforum   partforum object
 * @param  stdClass $course  course object
 * @param  stdClass $cm      course module object
 * @param  stdClass $context context object
 * @since Moodle 2.9
 */
function partforum_view($partforum, $course, $cm, $context) {

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);

    // Trigger course_module_viewed event.

    $params = array(
        'context' => $context,
        'objectid' => $partforum->id
    );

    $event = \mod_partforum\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('partforum', $partforum);
    $event->trigger();
}


/**
 * Trigger the discussion viewed event
 *
 * @param  stdClass $modcontext module context object
 * @param  stdClass $partforum      partforum object
 * @param  stdClass $discussion discussion object
 * @since Moodle 2.9
 */
function partforum_discussion_view($modcontext, $partforum, $discussion) {
    $params = array(
        'context' => $modcontext,
        'objectid' => $discussion->id,
    );

    $event = \mod_partforum\event\discussion_viewed::create($params);
    $event->add_record_snapshot('partforum_discussions', $discussion);
    $event->add_record_snapshot('partforum', $partforum);
    $event->trigger();
}
