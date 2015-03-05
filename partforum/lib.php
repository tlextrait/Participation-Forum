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
 * @package mod-forum
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
 * @param object $forum add forum instance (with magic quotes)
 * @return int intance id
 */
function partforum_add_instance($forum, $mform) {
    global $CFG, $DB;

    $forum->timemodified = time();

    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $forum->id = $DB->insert_record('partforum', $forum);
    $modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule);

    if ($forum->type == 'single') {  // Create related discussion.
        $discussion = new stdClass();
        $discussion->course        = $forum->course;
        $discussion->forum         = $forum->id;
        $discussion->name          = $forum->name;
        $discussion->assessed      = $forum->assessed;
        $discussion->message       = $forum->intro;
        $discussion->messageformat = $forum->introformat;
        $discussion->messagetrust  = trusttext_trusted(get_context_instance(CONTEXT_COURSE, $forum->course));
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

    if ($forum->forcesubscribe == PARTFORUM_INITIALSUBSCRIBE) {
    /// all users should be subscribed initially
    /// Note: partforum_get_potential_subscribers should take the forum context,
    /// but that does not exist yet, becuase the forum is only half build at this
    /// stage. However, because the forum is brand new, we know that there are
    /// no role assignments or overrides in the forum context, so using the
    /// course context gives the same list of users.
        $users = partforum_get_potential_subscribers($modcontext, 0, 'u.id, u.email', '');
        foreach ($users as $user) {
            partforum_subscribe($user->id, $forum->id);
        }
    }

    partforum_grade_item_update($forum);

    return $forum->id;
}


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @global object
 * @param object $forum forum instance (with magic quotes)
 * @return bool success
 */
function partforum_update_instance($forum, $mform) {
    global $DB, $OUTPUT, $USER;

    $forum->timemodified = time();
    $forum->id           = $forum->instance;

    if (empty($forum->assessed)) {
        $forum->assessed = 0;
    }

    if (empty($forum->ratingtime) or empty($forum->assessed)) {
        $forum->assesstimestart  = 0;
        $forum->assesstimefinish = 0;
    }

    $oldforum = $DB->get_record('partforum', array('id'=>$forum->id));

    // MDL-3942 - if the aggregation type or scale (i.e. max grade) changes then recalculate the grades for the entire forum
    // if  scale changes - do we need to recheck the ratings, if ratings higher than scale how do we want to respond?
    // for count and sum aggregation types the grade we check to make sure they do not exceed the scale (i.e. max score) when calculating the grade
    if (($oldforum->assessed<>$forum->assessed) or ($oldforum->scale<>$forum->scale)) {
        partforum_update_grades($forum); // recalculate grades for the forum
    }

    if ($forum->type == 'single') {  // Update related discussion and post.
        if (! $discussion = $DB->get_record('partforum_discussions', array('forum'=>$forum->id))) {
            if ($discussions = $DB->get_records('partforum_discussions', array('forum'=>$forum->id), 'timemodified ASC')) {
                echo $OUTPUT->notification('Warning! There is more than one discussion in this forum - using the most recent');
                $discussion = array_pop($discussions);
            } else {
                // try to recover by creating initial discussion - MDL-16262
                $discussion = new stdClass();
                $discussion->course          = $forum->course;
                $discussion->forum           = $forum->id;
                $discussion->name            = $forum->name;
                $discussion->assessed        = $forum->assessed;
                $discussion->message         = $forum->intro;
                $discussion->messageformat   = $forum->introformat;
                $discussion->messagetrust    = true;
                $discussion->mailnow         = false;
                $discussion->groupid         = -1;

                $message = '';

                partforum_add_discussion($discussion, null, $message);

                if (! $discussion = $DB->get_record('partforum_discussions', array('forum'=>$forum->id))) {
                    print_error('cannotadd', 'partforum');
                }
            }
        }
        if (! $post = $DB->get_record('partforum_posts', array('id'=>$discussion->firstpost))) {
            print_error('cannotfindfirstpost', 'partforum');
        }

        $cm         = get_coursemodule_from_instance('partforum', $forum->id);
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id, MUST_EXIST);

        if ($mform and $draftid = file_get_submitted_draft_itemid('introeditor')) {
            // ugly hack - we need to copy the files somehow
            $discussion = $DB->get_record('partforum_discussions', array('id'=>$discussion->id), '*', MUST_EXIST);
            $post = $DB->get_record('partforum_posts', array('id'=>$discussion->firstpost), '*', MUST_EXIST);

            $post->message = file_save_draft_area_files($draftid, $modcontext->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
        }

        $post->subject       = $forum->name;
        $post->message       = $forum->intro;
        $post->messageformat = $forum->introformat;
        $post->messagetrust  = trusttext_trusted($modcontext);
        $post->modified      = $forum->timemodified;
        $post->userid        = $USER->id;    // MDL-18599, so that current teacher can take ownership of activities

        $DB->update_record('partforum_posts', $post);
        $discussion->name = $forum->name;
        $DB->update_record('partforum_discussions', $discussion);
    }

    $DB->update_record('partforum', $forum);

    partforum_grade_item_update($forum);

    return true;
}


/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @global object
 * @param int $id forum instance id
 * @return bool success
 */
function partforum_delete_instance($id) {
    global $DB;

    if (!$forum = $DB->get_record('partforum', array('id'=>$id))) {
        return false;
    }
    if (!$cm = get_coursemodule_from_instance('partforum', $forum->id)) {
        return false;
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        return false;
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // now get rid of all files
    $fs = get_file_storage();
    $fs->delete_area_files($context->id);

    $result = true;

    if ($discussions = $DB->get_records('partforum_discussions', array('forum'=>$forum->id))) {
        foreach ($discussions as $discussion) {
            if (!partforum_delete_discussion($discussion, true, $course, $cm, $forum)) {
                $result = false;
            }
        }
    }

    if (!$DB->delete_records('partforum_subscriptions', array('forum'=>$forum->id))) {
        $result = false;
    }

    partforum_tp_delete_read_records(-1, -1, -1, $forum->id);

    if (!$DB->delete_records('partforum', array('id'=>$forum->id))) {
        $result = false;
    }

    partforum_grade_item_delete($forum);

    return $result;
}


/**
 * Indicates API features that the forum supports.
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
 * Obtains the automatic completion state for this forum based on any conditions
 * in forum settings.
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

    // Get forum details
    if (!($forum=$DB->get_record('partforum',array('id'=>$cm->instance)))) {
        throw new Exception("Can't find partforum {$cm->instance}");
    }

    $result=$type; // Default return value

    $postcountparams=array('userid'=>$userid,'forumid'=>$forum->id);
    $postcountsql="
SELECT
    COUNT(1)
FROM
    {partforum_posts} fp
    INNER JOIN {partforum_discussions} fd ON fp.discussion=fd.id
WHERE
    fp.userid=:userid AND fd.forum=:forumid";

    if ($forum->completiondiscussions) {
        $value = $forum->completiondiscussions <=
                 $DB->count_records('partforum_discussions',array('forum'=>$forum->id,'userid'=>$userid));
        if ($type == COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionreplies) {
        $value = $forum->completionreplies <=
                 $DB->get_field_sql( $postcountsql.' AND fp.parent<>0',$postcountparams);
        if ($type==COMPLETION_AND) {
            $result = $result && $value;
        } else {
            $result = $result || $value;
        }
    }
    if ($forum->completionposts) {
        $value = $forum->completionposts <= $DB->get_field_sql($postcountsql,$postcountparams);
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
    $forums          = array();
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
            $forumid = $discussions[$discussionid]->forum;
            if (!isset($forums[$forumid])) {
                if ($forum = $DB->get_record('partforum', array('id' => $forumid))) {
                    $forums[$forumid] = $forum;
                } else {
                    mtrace('Could not find forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            $courseid = $forums[$forumid]->course;
            if (!isset($courses[$courseid])) {
                if ($course = $DB->get_record('course', array('id' => $courseid))) {
                    $courses[$courseid] = $course;
                } else {
                    mtrace('Could not find course '.$courseid);
                    unset($posts[$pid]);
                    continue;
                }
            }
            if (!isset($coursemodules[$forumid])) {
                if ($cm = get_coursemodule_from_instance('partforum', $forumid, $courseid)) {
                    $coursemodules[$forumid] = $cm;
                } else {
                    mtrace('Could not find course module for forum '.$forumid);
                    unset($posts[$pid]);
                    continue;
                }
            }


            // caching subscribed users of each forum
            if (!isset($subscribedusers[$forumid])) {
                $modcontext = get_context_instance(CONTEXT_MODULE, $coursemodules[$forumid]->id);
                if ($subusers = partforum_subscribed_users($courses[$courseid], $forums[$forumid], 0, $modcontext, "u.*")) {
                    foreach ($subusers as $postuser) {
                        unset($postuser->description); // not necessary
                        // this user is subscribed to this forum
                        $subscribedusers[$forumid][$postuser->id] = $postuser->id;
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
            foreach ($coursemodules as $forumid=>$unused) {
                $coursemodules[$forumid]->cache       = new stdClass();
                $coursemodules[$forumid]->cache->caps = array();
                unset($coursemodules[$forumid]->uservisible);
            }

            foreach ($posts as $pid => $post) {

                // Set up the environment for the post, discussion, forum, course
                $discussion = $discussions[$post->discussion];
                $forum      = $forums[$discussion->forum];
                $course     = $courses[$forum->course];
                $cm         =& $coursemodules[$forum->id];

                // Do some checks  to see if we can bail out now
                // Only active enrolled users are in the list of subscribers
                if (!isset($subscribedusers[$forum->id][$userto->id])) {
                    continue; // user does not subscribe to this forum
                }

                // Don't send email if the forum is Q&A and the user has not posted
                if ($forum->type == 'qanda' && !partforum_get_user_posted_time($discussion->id, $userto->id)) {
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
                if (!isset($userto->viewfullnames[$forum->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                }
                if (!isset($userto->canpost[$discussion->id])) {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $userto->canpost[$discussion->id] = partforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                }
                if (!isset($userfrom->groups[$forum->id])) {
                    if (!isset($userfrom->groups)) {
                        $userfrom->groups = array();
                        $users[$userfrom->id]->groups = array();
                    }
                    $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                    $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
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
                if (!partforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
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

                $cleanforumname = str_replace('"', "'", strip_tags(format_string($forum->name)));

                $userfrom->customheaders = array (  // Headers to make emails easier to track
                           'Precedence: Bulk',
                           'List-Id: "'.$cleanforumname.'" <moodleforum'.$forum->id.'@'.$hostname.'>',
                           'List-Help: '.$CFG->wwwroot.'/mod/partforum/view.php?f='.$forum->id,
                           'Message-ID: <moodlepost'.$post->id.'@'.$hostname.'>',
                           'X-Course-Id: '.$course->id,
                           'X-Course-Name: '.format_string($course->fullname, true)
                );

                if ($post->parent) {  // This post is a reply, so add headers for threading (see MDL-22551)
                    $userfrom->customheaders[] = 'In-Reply-To: <moodlepost'.$post->parent.'@'.$hostname.'>';
                    $userfrom->customheaders[] = 'References: <moodlepost'.$post->parent.'@'.$hostname.'>';
                }

                $postsubject = "$course->shortname: ".format_string($post->subject,true);
                $posttext = partforum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto);
                $posthtml = partforum_make_mail_html($course, $cm, $forum, $discussion, $post, $userfrom, $userto);

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
                $smallmessagestrings->forumname = "{$course->shortname}: ".format_string($forum->name,true).": ".$discussion->name;
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

                // Mark post as read if forum_usermarksread is set off
                    if (!$CFG->forum_usermarksread) {
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

        mtrace('Sending forum digests: '.userdate($timenow, '', $sitetimezone));

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
                $forumid = $discussions[$discussionid]->forum;
                if (!isset($forums[$forumid])) {
                    if ($forum = $DB->get_record('partforum', array('id' => $forumid))) {
                        $forums[$forumid] = $forum;
                    } else {
                        continue;
                    }
                }

                $courseid = $forums[$forumid]->course;
                if (!isset($courses[$courseid])) {
                    if ($course = $DB->get_record('course', array('id' => $courseid))) {
                        $courses[$courseid] = $course;
                    } else {
                        continue;
                    }
                }

                if (!isset($coursemodules[$forumid])) {
                    if ($cm = get_coursemodule_from_instance('partforum', $forumid, $courseid)) {
                        $coursemodules[$forumid] = $cm;
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
                    $forum      = $forums[$discussion->forum];
                    $course     = $courses[$forum->course];
                    $cm         = $coursemodules[$forum->id];

                    //override language
                    cron_setup_user($userto, $course);

                    // Fill caches
                    if (!isset($userto->viewfullnames[$forum->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->viewfullnames[$forum->id] = has_capability('moodle/site:viewfullnames', $modcontext);
                    }
                    if (!isset($userto->canpost[$discussion->id])) {
                        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $userto->canpost[$discussion->id] = partforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
                    }

                    $strforums      = get_string('forums', 'partforum');
                    $canunsubscribe = ! partforum_is_forcesubscribed($forum);
                    $canreply       = $userto->canpost[$discussion->id];

                    $posttext .= "\n \n";
                    $posttext .= '=====================================================================';
                    $posttext .= "\n \n";
                    $posttext .= "$course->shortname -> $strforums -> ".format_string($forum->name,true);
                    if ($discussion->name != $forum->name) {
                        $posttext  .= " -> ".format_string($discussion->name,true);
                    }
                    $posttext .= "\n";

                    $posthtml .= "<p><font face=\"sans-serif\">".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/partforum/index.php?id=$course->id\">$strforums</a> -> ".
                    "<a target=\"_blank\" href=\"$CFG->wwwroot/mod/partforum/view.php?f=$forum->id\">".format_string($forum->name,true)."</a>";
                    if ($discussion->name == $forum->name) {
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

                        if (!isset($userfrom->groups[$forum->id])) {
                            if (!isset($userfrom->groups)) {
                                $userfrom->groups = array();
                                $users[$userfrom->id]->groups = array();
                            }
                            $userfrom->groups[$forum->id] = groups_get_all_groups($course->id, $userfrom->id, $cm->groupingid);
                            $users[$userfrom->id]->groups[$forum->id] = $userfrom->groups[$forum->id];
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
                            $posttext .= partforum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto, true);
                            $posthtml .= partforum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

                        // Create an array of postid's for this user to mark as read.
                            if (!$CFG->forum_usermarksread) {
                                $userto->markposts[$post->id] = $post->id;
                            }
                        }
                    }
                    if ($canunsubscribe) {
                        $posthtml .= "\n<div class='mdl-right'><font size=\"1\"><a href=\"$CFG->wwwroot/mod/partforum/subscribe.php?id=$forum->id\">".get_string("unsubscribe", "partforum")."</a></font></div>";
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
                //directly email forum digests rather than sending them via messaging
                $mailresult = email_to_user($userto, $site->shortname, $postsubject, $posttext, $posthtml, $attachment, $attachname, $usetrueaddress, $CFG->forum_replytouser);

                if (!$mailresult) {
                    mtrace("ERROR!");
                    echo "Error: mod/partforum/cron.php: Could not send out digest mail to user $userto->id ($userto->email)... not trying again.\n";
                    add_to_log($course->id, 'partforum', 'mail digest error', '', '', $cm->id, $userto->id);
                } else {
                    mtrace("success.");
                    $usermailcount++;

                    // Mark post as read if forum_usermarksread is set off
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

    if (!empty($CFG->forum_lastreadclean)) {
        $timenow = time();
        if ($CFG->forum_lastreadclean + (24*3600) < $timenow) {
            set_config('forum_lastreadclean', $timenow);
            mtrace('Removing old forum read tracking info...');
            partforum_tp_clean_read_records();
        }
    } else {
        set_config('forum_lastreadclean', time());
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
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @param boolean $bare
 * @return string The email body in plain text format.
 */
function partforum_make_mail_text($course, $cm, $forum, $discussion, $post, $userfrom, $userto, $bare = false) {
    global $CFG, $USER;

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (!isset($userto->viewfullnames[$forum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$forum->id];
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = partforum_user_can_post($forum, $discussion, $userto, $cm, $course, $modcontext);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $by = New stdClass;
    $by->name = fullname($userfrom, $viewfullnames);
    $by->date = userdate($post->modified, "", $userto->timezone);

    $strbynameondate = get_string('bynameondate', 'partforum', $by);

    $strforums = get_string('forums', 'partforum');

    $canunsubscribe = ! partforum_is_forcesubscribed($forum);

    $posttext = '';

    if (!$bare) {
        $posttext  = "$course->shortname -> $strforums -> ".format_string($forum->name,true);

        if ($discussion->name != $forum->name) {
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
        $posttext .= ": $CFG->wwwroot/mod/partforum/subscribe.php?id=$forum->id\n";
    }

    return $posttext;
}

/**
 * Builds and returns the body of the email notification in html format.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $userfrom
 * @param object $userto
 * @return string The email text in HTML format
 */
function partforum_make_mail_html($course, $cm, $forum, $discussion, $post, $userfrom, $userto) {
    global $CFG;

    if ($userto->mailformat != 1) {  // Needs to be HTML
        return '';
    }

    if (!isset($userto->canpost[$discussion->id])) {
        $canreply = partforum_user_can_post($forum, $discussion, $userto);
    } else {
        $canreply = $userto->canpost[$discussion->id];
    }

    $strforums = get_string('forums', 'partforum');
    $canunsubscribe = ! partforum_is_forcesubscribed($forum);

    $posthtml = '<head>';
    $posthtml .= '</head>';
    $posthtml .= "\n<body id=\"email\">\n\n";

    $posthtml .= '<div class="navbar">'.
    '<a target="_blank" href="'.$CFG->wwwroot.'/course/view.php?id='.$course->id.'">'.$course->shortname.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/index.php?id='.$course->id.'">'.$strforums.'</a> &raquo; '.
    '<a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$forum->id.'">'.format_string($forum->name,true).'</a>';
    if ($discussion->name == $forum->name) {
        $posthtml .= '</div>';
    } else {
        $posthtml .= ' &raquo; <a target="_blank" href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$discussion->id.'">'.
                     format_string($discussion->name,true).'</a></div>';
    }
    $posthtml .= partforum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto, false, $canreply, true, false);

    if ($canunsubscribe) {
        $posthtml .= '<hr /><div class="mdl-align unsubscribelink">
                      <a href="'.$CFG->wwwroot.'/mod/partforum/subscribe.php?id='.$forum->id.'">'.get_string('unsubscribe', 'partforum').'</a>&nbsp;
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
 * @param object $forum
 * @return object A standard object with 2 variables: info (number of posts for this user) and time (last modified)
 */
function partforum_user_outline($course, $user, $mod, $forum) {
    global $CFG;
    require_once("$CFG->libdir/gradelib.php");
    $grades = grade_get_grades($course->id, 'mod', 'partforum', $forum->id, $user->id);
    if (empty($grades->items[0]->grades)) {
        $grade = false;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $count = partforum_count_user_posts($forum->id, $user->id);

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
 * @param object $forum
 */
function partforum_user_complete($course, $user, $mod, $forum) {
    global $CFG,$USER, $OUTPUT;
    require_once("$CFG->libdir/gradelib.php");

    $grades = grade_get_grades($course->id, 'mod', 'partforum', $forum->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($posts = partforum_get_user_posts($forum->id, $user->id)) {

        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
        $discussions = partforum_get_user_involved_discussions($forum->id, $user->id);

        foreach ($posts as $post) {
            if (!isset($discussions[$post->discussion])) {
                continue;
            }
            $discussion = $discussions[$post->discussion];

            partforum_print_post($post, $discussion, $forum, $cm, $course, false, false, false);
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

    if (!$forums = get_all_instances_in_courses('partforum',$courses)) {
        return;
    }


    // get all forum logs in ONE query (much better!)
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

    // also get all forum tracking stuff ONCE.
    $trackingforums = array();
    foreach ($forums as $forum) {
        if (partforum_tp_can_track_forums($forum)) {
            $trackingforums[$forum->id] = $forum;
        }
    }

    if (count($trackingforums) > 0) {
        $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.forum,d.course,COUNT(p.id) AS count '.
            ' FROM {partforum_posts} p '.
            ' JOIN {partforum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {partforum_read} r ON r.postid = p.id AND r.userid = ? WHERE (';
        $params = array($USER->id);

        foreach ($trackingforums as $track) {
            $sql .= '(d.forum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
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
        $sql .= ') AND p.modified >= ? AND r.id is NULL GROUP BY d.forum,d.course';
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

    $strforum = get_string('modulename','partforum');
    $strnumunread = get_string('overviewnumunread','partforum');
    $strnumpostssince = get_string('overviewnumpostssince','partforum');

    foreach ($forums as $forum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($forum->id, $new) && !empty($new[$forum->id])) {
            $count = $new[$forum->id]->count;
        }
        if (array_key_exists($forum->id,$unread)) {
            $thisunread = $unread[$forum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview forum"><div class="name">'.$strforum.': <a title="'.$strforum.'" href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$forum->id.'">'.
                $forum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= $count.' '.$strnumpostssince."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.$thisunread .' '.$strnumunread.'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($forum->course,$htmlarray)) {
                $htmlarray[$forum->course] = array();
            }
            if (!array_key_exists('partforum',$htmlarray[$forum->course])) {
                $htmlarray[$forum->course]['partforum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$forum->course]['partforum'] .= $str;
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

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture
                                         FROM {partforum_posts} p
                                              JOIN {partforum_discussions} d ON d.id = p.discussion
                                              JOIN {partforum} f             ON f.id = d.forum
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
        if (!isset($modinfo->instances['partforum'][$post->forum])) {
            // not visible
            continue;
        }
        $cm = $modinfo->instances['partforum'][$post->forum];
        if (!$cm->uservisible) {
            continue;
        }
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);

        if (!has_capability('mod/partforum:viewdiscussion', $context)) {
            continue;
        }

        if (!empty($CFG->forum_enabletimedposts) and $USER->id != $post->duserid
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

    echo $OUTPUT->heading(get_string('newforumposts', 'partforum').':', 3);
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
 * @param object $forum
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function partforum_get_user_grades($forum, $userid = 0) {
    global $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ratingoptions = new stdClass;
    $ratingoptions->component = 'mod_partforum';
    $ratingoptions->ratingarea = 'post';

    //need these to work backwards to get a context id. Is there a better way to get contextid from a module instance?
    $ratingoptions->modulename = 'partforum';
    $ratingoptions->moduleid   = $forum->id;
    $ratingoptions->userid = $userid;
    $ratingoptions->aggregationmethod = $forum->assessed;
    $ratingoptions->scaleid = $forum->scale;
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
 * @param object $forum
 * @param int $userid specific user only, 0 means all
 * @param boolean $nullifnone return null if grade does not exist
 * @return void
 */
function partforum_update_grades($forum, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    if (!$forum->assessed) {
        partforum_grade_item_update($forum);

    } else if ($grades = partforum_get_user_grades($forum, $userid)) {
        partforum_grade_item_update($forum, $grades);

    } else if ($userid and $nullifnone) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = NULL;
        partforum_grade_item_update($forum, $grade);

    } else {
        partforum_grade_item_update($forum);
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
        $pbar = new progress_bar('forumupgradegrades', 500, true);
        $i=0;
        foreach ($rs as $forum) {
            $i++;
            upgrade_set_timeout(60*5); // set up timeout, may also abort execution
            partforum_update_grades($forum, 0, false);
            $pbar->update($i, $count, "Updating Forum grades ($i/$count).");
        }
    }
    $rs->close();
}

/**
 * Create/update grade item for given forum
 *
 * @global object
 * @uses GRADE_TYPE_NONE
 * @uses GRADE_TYPE_VALUE
 * @uses GRADE_TYPE_SCALE
 * @param object $forum object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok
 */
function partforum_grade_item_update($forum, $grades=NULL) {
    global $CFG;
    if (!function_exists('grade_update')) { //workaround for buggy PHP versions
        require_once($CFG->libdir.'/gradelib.php');
    }
    
    if($forum->cmidnumber != ''){
        $params = array('itemname'=>$forum->name, 'idnumber'=>$forum->cmidnumber);
	}else{
	    $params = array('itemname'=>$forum->name);
	}
    
    if (!$forum->assessed or $forum->scale == 0) {
        $params['gradetype'] = GRADE_TYPE_NONE;

    } else if ($forum->scale > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $forum->scale;
        $params['grademin']  = 0;

    } else if ($forum->scale < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$forum->scale;
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = NULL;
    }

    return grade_update('mod/partforum', $forum->course, 'mod', 'partforum', $forum->id, 0, $grades, $params);
}

/**
 * Delete grade item for given forum
 *
 * @global object
 * @param object $forum object
 * @return object grade_item
 */
function partforum_grade_item_delete($forum) {
    global $CFG;
    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/partforum', $forum->course, 'mod', 'partforum', $forum->id, 0, NULL, array('deleted'=>1));
}


/**
 * Returns the users with data in one forum
 * (users with records in forum_subscriptions, forum_posts, students)
 *
 * @todo: deprecated - to be deleted in 2.2
 *
 * @param int $forumid
 * @return mixed array or false if none
 */
function partforum_get_participants($forumid) {

    global $CFG, $DB;

    $params = array('forumid' => $forumid);

    //Get students from forum_subscriptions
    $sql = "SELECT DISTINCT u.id, u.id
              FROM {user} u,
                   {partforum_subscriptions} s
             WHERE s.forum = :forumid AND
                   u.id = s.userid";
    $st_subscriptions = $DB->get_records_sql($sql, $params);

    //Get students from forum_posts
    $sql = "SELECT DISTINCT u.id, u.id
              FROM {user} u,
                   {partforum_discussions} d,
                   {partforum_posts} p
              WHERE d.forum = :forumid AND
                    p.discussion = d.id AND
                    u.id = p.userid";
    $st_posts = $DB->get_records_sql($sql, $params);

    //Get students from the ratings table
    $sql = "SELECT DISTINCT r.userid, r.userid AS id
              FROM {partforum_discussions} d
              JOIN {partforum_posts} p ON p.discussion = d.id
              JOIN {rating} r on r.itemid = p.id
             WHERE d.forum = :forumid AND
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
 * This function returns if a scale is being used by one forum
 *
 * @global object
 * @param int $forumid
 * @param int $scaleid negative number
 * @return bool
 */
function partforum_scale_used ($forumid,$scaleid) {
    global $DB;
    $return = false;

    $rec = $DB->get_record("partforum",array("id" => "$forumid","scale" => "-$scaleid"));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of forum
 *
 * This is used to find out if scale used anywhere
 *
 * @global object
 * @param $scaleid int
 * @return boolean True if the scale is used by any forum
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
 * Most of these joins are just to get the forum id
 *
 * @global object
 * @global object
 * @param int $postid
 * @return mixed array of posts or false
 */
function partforum_get_post_full($postid) {
    global $CFG, $DB;

    return $DB->get_record_sql("SELECT p.*, d.forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                             FROM {partforum_posts} p
                                  JOIN {partforum_discussions} d ON p.discussion = d.id
                                  LEFT JOIN {user} u ON p.userid = u.id
                            WHERE p.id = ?", array($postid));
}

/**
 * Gets posts with all info ready for partforum_print_post
 * We pass forumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @return mixed array of posts or false
 */
function partforum_get_discussion_posts($discussion, $sort, $forumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $forumid AS forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
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
 * @param bool $tracking does user track the forum?
 * @return array of posts
 */
function partforum_get_all_discussion_posts($discussionid, $sort, $tracking=false) {
    global $CFG, $DB, $USER;

    $tr_sel  = "";
    $tr_join = "";
    $params = array();

    if ($tracking) {
        $now = time();
        $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);
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
 * We pass forumid in because we always know it so no need to make a
 * complicated join to find it out.
 *
 * @global object
 * @global object
 * @param int $parent
 * @param int $forumid
 * @return array
 */
function partforum_get_child_posts($parent, $forumid) {
    global $CFG, $DB;

    return $DB->get_records_sql("SELECT p.*, $forumid AS forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {partforum_posts} p
                         LEFT JOIN {user} u ON p.userid = u.id
                             WHERE p.parent = ?
                          ORDER BY p.created ASC", array($parent));
}

/**
 * An array of forum objects that the user is allowed to read/search through.
 *
 * @global object
 * @global object
 * @global object
 * @param int $userid
 * @param int $courseid if 0, we look for forums throughout the whole site.
 * @return array of forum objects, or false if no matches
 *         Forum objects have the following attributes:
 *         id, type, course, cmid, cmvisible, cmgroupmode, accessallgroups,
 *         viewhiddentimedposts
 */
function partforum_get_readable_forums($userid, $courseid=0) {

    global $CFG, $DB, $USER;
    require_once($CFG->dirroot.'/course/lib.php');

    if (!$forummod = $DB->get_record('modules', array('name' => 'partforum'))) {
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

    $readableforums = array();

    foreach ($courses as $course) {

        $modinfo =& get_fast_modinfo($course);
        if (is_null($modinfo->groups)) {
            $modinfo->groups = groups_get_user_groups($course->id, $userid);
        }

        if (empty($modinfo->instances['partforum'])) {
            // hmm, no forums?
            continue;
        }

        $courseforums = $DB->get_records('partforum', array('course' => $course->id));

        foreach ($modinfo->instances['partforum'] as $forumid => $cm) {
            if (!$cm->uservisible or !isset($courseforums[$forumid])) {
                continue;
            }
            $context = get_context_instance(CONTEXT_MODULE, $cm->id);
            $forum = $courseforums[$forumid];
            $forum->context = $context;
            $forum->cm = $cm;

            if (!has_capability('mod/partforum:viewdiscussion', $context)) {
                continue;
            }

         /// group access
            if (groups_get_activity_groupmode($cm, $course) == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $context)) {
                if (is_null($modinfo->groups)) {
                    $modinfo->groups = groups_get_user_groups($course->id, $USER->id);
                }
                if (isset($modinfo->groups[$cm->groupingid])) {
                    $forum->onlygroups = $modinfo->groups[$cm->groupingid];
                    $forum->onlygroups[] = -1;
                } else {
                    $forum->onlygroups = array(-1);
                }
            }

        /// hidden timed discussions
            $forum->viewhiddentimedposts = true;
            if (!empty($CFG->forum_enabletimedposts)) {
                if (!has_capability('mod/partforum:viewhiddentimedposts', $context)) {
                    $forum->viewhiddentimedposts = false;
                }
            }

        /// qanda access
            if ($forum->type == 'qanda'
                    && !has_capability('mod/partforum:viewqandawithoutposting', $context)) {

                // We need to check whether the user has posted in the qanda forum.
                $forum->onlydiscussions = array();  // Holds discussion ids for the discussions
                                                    // the user is allowed to see in this forum.
                if ($discussionspostedin = partforum_discussions_user_has_posted_in($forum->id, $USER->id)) {
                    foreach ($discussionspostedin as $d) {
                        $forum->onlydiscussions[] = $d->id;
                    }
                }
            }

            $readableforums[$forum->id] = $forum;
        }

        unset($modinfo);

    } // End foreach $courses

    return $readableforums;
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

    $forums = partforum_get_readable_forums($USER->id, $courseid);

    if (count($forums) == 0) {
        $totalcount = 0;
        return false;
    }

    $now = round(time(), -2); // db friendly

    $fullaccess = array();
    $where = array();
    $params = array();

    foreach ($forums as $forumid => $forum) {
        $select = array();

        if (!$forum->viewhiddentimedposts) {
            $select[] = "(d.userid = :userid{$forumid} OR (d.timestart < :timestart{$forumid} AND (d.timeend = 0 OR d.timeend > :timeend{$forumid})))";
            $params = array_merge($params, array('userid'.$forumid=>$USER->id, 'timestart'.$forumid=>$now, 'timeend'.$forumid=>$now));
        }

        $cm = $forum->cm;
        $context = $forum->context;

        if ($forum->type == 'qanda'
            && !has_capability('mod/partforum:viewqandawithoutposting', $context)) {
            if (!empty($forum->onlydiscussions)) {
                list($discussionid_sql, $discussionid_params) = $DB->get_in_or_equal($forum->onlydiscussions, SQL_PARAMS_NAMED, 'qanda'.$forumid.'_');
                $params = array_merge($params, $discussionid_params);
                $select[] = "(d.id $discussionid_sql OR p.parent = 0)";
            } else {
                $select[] = "p.parent = 0";
            }
        }

        if (!empty($forum->onlygroups)) {
            list($groupid_sql, $groupid_params) = $DB->get_in_or_equal($forum->onlygroups, SQL_PARAMS_NAMED, 'grps'.$forumid.'_');
            $params = array_merge($params, $groupid_params);
            $select[] = "d.groupid $groupid_sql";
        }

        if ($select) {
            $selects = implode(" AND ", $select);
            $where[] = "(d.forum = :forum{$forumid} AND $selects)";
            $params['partforum'.$forumid] = $forumid;
        } else {
            $fullaccess[] = $forumid;
        }
    }

    if ($fullaccess) {
        list($fullid_sql, $fullid_params) = $DB->get_in_or_equal($fullaccess, SQL_PARAMS_NAMED, 'fula');
        $params = array_merge($params, $fullid_params);
        $where[] = "(d.forum $fullid_sql)";
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
    // Requires manual creation of text index for forum_posts before enabling it:
    // CREATE FULLTEXT INDEX foru_post_tix ON [prefix]forum_posts (subject, message)
    // Experimental feature under 1.8! MDL-8830
        if (!empty($CFG->forum_usetextsearches)) {
            list($messagesearch, $msparams) = search_generate_text_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.forum');
        } else {
            list($messagesearch, $msparams) = search_generate_SQL($parsearray, 'p.message', 'p.subject',
                                                 'p.userid', 'u.id', 'u.firstname',
                                                 'u.lastname', 'p.modified', 'd.forum');
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
                         d.forum,
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
    if (!empty($CFG->forum_enabletimedposts)) {
        if (empty($now)) {
            $now = time();
        }
        $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    return $DB->get_records_sql("SELECT p.*, d.course, d.forum
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

    if (empty($CFG->forum_enabletimedposts)) {
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
 * Get all the posts for a user in a forum suitable for partforum_print_post
 *
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @return array
 */
function partforum_get_user_posts($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);

    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $forumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT p.*, d.forum, u.firstname, u.lastname, u.email, u.picture, u.imagealt
                              FROM {partforum} f
                                   JOIN {partforum_discussions} d ON d.forum = f.id
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
 * @param int $forumid
 * @param int $userid
 * @return array Array or false
 */
function partforum_get_user_involved_discussions($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);
    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $forumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_records_sql("SELECT DISTINCT d.*
                              FROM {partforum} f
                                   JOIN {partforum_discussions} d ON d.forum = f.id
                                   JOIN {partforum_posts} p       ON p.discussion = d.id
                             WHERE f.id = ?
                                   AND p.userid = ?
                                   $timedsql", $params);
}

/**
 * Get all the posts for a user in a forum suitable for partforum_print_post
 *
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array of counts or false
 */
function partforum_count_user_posts($forumid, $userid) {
    global $CFG, $DB;

    $timedsql = "";
    $params = array($forumid, $userid);
    if (!empty($CFG->forum_enabletimedposts)) {
        $cm = get_coursemodule_from_instance('partforum', $forumid);
        if (!has_capability('mod/partforum:viewhiddentimedposts' , get_context_instance(CONTEXT_MODULE, $cm->id))) {
            $now = time();
            $timedsql = "AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?))";
            $params[] = $now;
            $params[] = $now;
        }
    }

    return $DB->get_record_sql("SELECT COUNT(p.id) AS postcount, MAX(p.modified) AS lastpost
                             FROM {partforum} f
                                  JOIN {partforum_discussions} d ON d.forum = f.id
                                  JOIN {partforum_posts} p       ON p.discussion = d.id
                                  JOIN {user} u              ON u.id = p.userid
                            WHERE f.id = ?
                                  AND p.userid = ?
                                  $timedsql", $params);
}

/**
 * Given a log entry, return the forum post details for it.
 *
 * @global object
 * @global object
 * @param object $log
 * @return array|null
 */
function partforum_get_post_from_log($log) {
    global $CFG, $DB;

    if ($log->action == "add post") {

        return $DB->get_record_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {partforum_discussions} d,
                                      {partforum_posts} p,
                                      {partforum} f,
                                      {user} u
                                WHERE p.id = ?
                                  AND d.id = p.discussion
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.forum", array($log->info));


    } else if ($log->action == "add discussion") {

        return $DB->get_record_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                           u.firstname, u.lastname, u.email, u.picture
                                 FROM {partforum_discussions} d,
                                      {partforum_posts} p,
                                      {partforum} f,
                                      {user} u
                                WHERE d.id = ?
                                  AND d.firstpost = p.id
                                  AND p.userid = u.id
                                  AND u.deleted <> '1'
                                  AND f.id = d.forum", array($log->info));
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
 * @param int $forumid
 * @param string $forumsort
 * @param int $limit
 * @param int $page
 * @param int $perpage
 * @return array
 */
function partforum_count_discussion_replies($forumid, $forumsort="", $limit=-1, $page=-1, $perpage=0) {
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

    if ($forumsort == "") {
        $orderby = "";
        $groupby = "";

    } else {
        $orderby = "ORDER BY $forumsort";
        $groupby = ", ".strtolower($forumsort);
        $groupby = str_replace('desc', '', $groupby);
        $groupby = str_replace('asc', '', $groupby);
    }

    if (($limitfrom == 0 and $limitnum == 0) or $forumsort == "") {
        $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d ON p.discussion = d.id
                 WHERE p.parent > 0 AND d.forum = ?
              GROUP BY p.discussion";
        return $DB->get_records_sql($sql, array($forumid));

    } else {
        $sql = "SELECT p.discussion, (COUNT(p.id) - 1) AS replies, MAX(p.id) AS lastpostid
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d ON p.discussion = d.id
                 WHERE d.forum = ?
              GROUP BY p.discussion $groupby
              $orderby";
        return $DB->get_records_sql("SELECT * FROM ($sql) sq", array($forumid), $limitfrom, $limitnum);
    }
}

/**
 * @global object
 * @global object
 * @global object
 * @staticvar array $cache
 * @param object $forum
 * @param object $cm
 * @param object $course
 * @return mixed
 */
function partforum_count_discussions($forum, $cm, $course) {
    global $CFG, $DB, $USER;

    static $cache = array();

    $now = round(time(), -2); // db cache friendliness

    $params = array($course->id);

    if (!isset($cache[$course->id])) {
        if (!empty($CFG->forum_enabletimedposts)) {
            $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
            $params[] = $now;
            $params[] = $now;
        } else {
            $timedsql = "";
        }

        $sql = "SELECT f.id, COUNT(d.id) as dcount
                  FROM {partforum} f
                       JOIN {partforum_discussions} d ON d.forum = f.id
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

    if (empty($cache[$course->id][$forum->id])) {
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $cache[$course->id][$forum->id];
    }

    if (has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        return $cache[$course->id][$forum->id];
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
    $params[] = $forum->id;

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < $now AND (d.timeend = 0 OR d.timeend > $now)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT COUNT(d.id)
              FROM {partforum_discussions} d
             WHERE d.groupid $mygroups_sql AND d.forum = ?
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
 * Get all discussions in a forum
 *
 * @global object
 * @global object
 * @global object
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $cm
 * @param string $forumsort
 * @param bool $fullpost
 * @param int $unused
 * @param int $limit
 * @param bool $userlastmodified
 * @param int $page
 * @param int $perpage
 * @return array
 */
function partforum_get_discussions($cm, $forumsort="d.timemodified DESC", $fullpost=true, $unused=-1, $limit=-1, $userlastmodified=false, $page=-1, $perpage=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (!has_capability('mod/partforum:viewdiscussion', $modcontext)) { /// User must have perms to view discussions
        return array();
    }

    if (!empty($CFG->forum_enabletimedposts)) { /// Users must fulfill timed posts

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
            $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
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


    if (empty($forumsort)) {
        $forumsort = "d.timemodified DESC";
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
             WHERE d.forum = ? AND p.parent = 0
                   $timelimit $groupselect
          ORDER BY $forumsort";
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
    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);

    $params = array();
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    if ($groupmode) {
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

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

    if (!empty($CFG->forum_enabletimedposts)) {
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
             WHERE d.forum = {$cm->instance}
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
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

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

    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);

    $timelimit = "";

    if (!empty($CFG->forum_enabletimedposts)) {

        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

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
             WHERE d.forum = ? AND p.parent = 0
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
                                   f.type as forumtype, f.name as forumname, f.id as forumid
                              FROM {partforum_discussions} d,
                                   {partforum_posts} p,
                                   {user} u,
                                   {partforum} f
                             WHERE d.course = ?
                               AND p.discussion = d.id
                               AND p.parent = 0
                               AND p.userid = u.id
                               AND u.id = ?
                               AND d.forum = f.id $groupselect
                          ORDER BY p.created DESC", $params);
}

/**
 * Get the list of potential subscribers to a forum.
 *
 * @param object $forumcontext the forum context.
 * @param integer $groupid the id of a group, or 0 for all groups.
 * @param string $fields the list of fields to return for each user. As for get_users_by_capability.
 * @param string $sort sort order. As for get_users_by_capability.
 * @return array list of users.
 */
function partforum_get_potential_subscribers($forumcontext, $groupid, $fields, $sort) {
    global $DB;

    // only active enrolled users or everybody on the frontpage with this capability
    list($esql, $params) = get_enrolled_sql($forumcontext, 'mod/partforum:initialsubscriptions', $groupid, true);

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
 * Returns list of user objects that are subscribed to this forum
 *
 * @global object
 * @global object
 * @param object $course the course
 * @param forum $forum the forum
 * @param integer $groupid group id, or 0 for all.
 * @param object $context the forum context, to save re-fetching it where possible.
 * @param string $fields requested user fields (with "u." table prefix)
 * @return array list of users.
 */
function partforum_subscribed_users($course, $forum, $groupid=0, $context = null, $fields = null) {
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
                  u.trackforums,
                  u.mnethostid";
    }

    if (empty($context)) {
        $cm = get_coursemodule_from_instance('partforum', $forum->id, $course->id);
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    if (partforum_is_forcesubscribed($forum)) {
        $results = partforum_get_potential_subscribers($context, $groupid, $fields, "u.email ASC");

    } else {
        // only active enrolled users or everybody on the frontpage
        list($esql, $params) = get_enrolled_sql($context, '', $groupid, true);
        $params['forumid'] = $forum->id;
        $results = $DB->get_records_sql("SELECT $fields
                                           FROM {user} u
                                           JOIN ($esql) je ON je.id = u.id
                                           JOIN {partforum_subscriptions} s ON s.userid = u.id
                                          WHERE s.forum = :forumid
                                       ORDER BY u.email ASC", $params);
    }

    // Guest user should never be subscribed to a forum.
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
function partforum_get_course_forum($courseid, $type) {
// How to set up special 1-per-course forums
    global $CFG, $DB, $OUTPUT;

    if ($forums = $DB->get_records_select("partforum", "course = ? AND type = ?", array($courseid, $type), "id ASC")) {
        // There should always only be ONE, but with the right combination of
        // errors there might be more.  In this case, just return the oldest one (lowest ID).
        foreach ($forums as $forum) {
            return $forum;   // ie the first one
        }
    }

    // Doesn't exist, so create one now.
    $forum->course = $courseid;
    $forum->type = "$type";
    switch ($forum->type) {
        case "news":
            $forum->name  = get_string("namenews", "partforum");
            $forum->intro = get_string("intronews", "partforum");
            $forum->forcesubscribe = PARTFORUM_FORCESUBSCRIBE;
            $forum->assessed = 0;
            if ($courseid == SITEID) {
                $forum->name  = get_string("sitenews");
                $forum->forcesubscribe = 0;
            }
            break;
        case "social":
            $forum->name  = get_string("namesocial", "partforum");
            $forum->intro = get_string("introsocial", "partforum");
            $forum->assessed = 0;
            $forum->forcesubscribe = 0;
            break;
        case "blog":
            $forum->name = get_string('blogforum', 'partforum');
            $forum->intro = get_string('introblog', 'partforum');
            $forum->assessed = 0;
            $forum->forcesubscribe = 0;
            break;
        default:
            echo $OUTPUT->notification("That forum type doesn't exist!");
            return false;
            break;
    }

    $forum->timemodified = time();
    $forum->id = $DB->insert_record("partforum", $forum);

    if (! $module = $DB->get_record("modules", array("name" => "partforum"))) {
        echo $OUTPUT->notification("Could not find forum module!!");
        return false;
    }
    $mod = new stdClass();
    $mod->course = $courseid;
    $mod->module = $module->id;
    $mod->instance = $forum->id;
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

    return $DB->get_record("partforum", array("id" => "$forum->id"));
}


/**
 * Given the data about a posting, builds up the HTML to display it and
 * returns the HTML in a string.  This is designed for sending via HTML email.
 *
 * @global object
 * @param object $course
 * @param object $cm
 * @param object $forum
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
function partforum_make_mail_post($course, $cm, $forum, $discussion, $post, $userfrom, $userto,
                              $ownpost=false, $reply=false, $link=false, $rate=false, $footer="") {

    global $CFG, $OUTPUT;

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    if (!isset($userto->viewfullnames[$forum->id])) {
        $viewfullnames = has_capability('moodle/site:viewfullnames', $modcontext, $userto->id);
    } else {
        $viewfullnames = $userto->viewfullnames[$forum->id];
    }

    // add absolute file links
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $modcontext->id, 'mod_partforum', 'post', $post->id);

    // format the post body
    $options = new stdClass();
    $options->para = true;
    $formattedtext = format_text($post->message, $post->messageformat, $options, $course->id);

    $output = '<table border="0" cellpadding="3" cellspacing="0" class="forumpost">';

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
        $groups = $userfrom->groups[$forum->id];
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
 * Print a forum post
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
 * @param object $forum
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
 * @param boolean $dummyifcantsee When forum_user_can_see_post says that
 *          the current user can't see this post, if this argument is true
 *          (the default) then print a dummy 'you can't see this post' post.
 *          If false, don't output anything at all.
 * @param bool|null $istracked
 * @return void
 */
function partforum_print_post($post, $discussion, $forum, &$cm, $course, $ownpost=false, $reply=false, $link=false,
                          $footer="", $highlight="", $postisread=null, $dummyifcantsee=true, $istracked=null, $return=false) {
    global $USER, $CFG, $OUTPUT;

    require_once($CFG->libdir . '/filelib.php');

    // String cache
    static $str;

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post->course = $course->id;
    $post->forum  = $forum->id;
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
        $cm->uservisible = coursemodule_visible_for_user($cm);
    }

    if ($istracked && is_null($postisread)) {
        $postisread = partforum_tp_is_post_read($USER->id, $post);
    }

    if (!partforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
        $output = '';
        if (!$dummyifcantsee) {
            if ($return) {
                return $output;
            }
            echo $output;
            return;
        }
        $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
        $output .= html_writer::start_tag('div', array('class'=>'forumpost clearfix'));
        $output .= html_writer::start_tag('div', array('class'=>'row header'));
        $output .= html_writer::tag('div', '', array('class'=>'left picture')); // Picture
        if ($post->parent) {
            $output .= html_writer::start_tag('div', array('class'=>'topic'));
        } else {
            $output .= html_writer::start_tag('div', array('class'=>'topic starter'));
        }
        $output .= html_writer::tag('div', get_string('forumsubjecthidden','partforum'), array('class'=>'subject')); // Subject
        $output .= html_writer::tag('div', get_string('forumauthorhidden','partforum'), array('class'=>'author')); // author
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::start_tag('div', array('class'=>'row'));
        $output .= html_writer::tag('div', '&nbsp;', array('class'=>'left side')); // Groups
        $output .= html_writer::tag('div', get_string('forumbodyhidden','partforum'), array('class'=>'content')); // Content
        $output .= html_writer::end_tag('div'); // row
        $output .= html_writer::end_tag('div'); // forumpost

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
        $str->displaymode     = get_user_preferences('forum_displaymode', $CFG->forum_displaymode);
        $str->markread     = get_string('markread', 'partforum');
        $str->markunread   = get_string('markunread', 'partforum');
    }

    $discussionlink = new moodle_url('/mod/partforum/discuss.php', array('d'=>$post->discussion));

    // Build an object that represents the posting user
    $postuser = new stdClass;
    $postuser->id        = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname  = $post->lastname;
    $postuser->imagealt  = $post->imagealt;
    $postuser->picture   = $post->picture;
    $postuser->email     = $post->email;
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
    $shortenpost = ($link && (strlen(strip_tags($post->message)) > $CFG->forum_longpost));


    // Prepare an array of commands
    $commands = array();

    // SPECIAL CASE: The front page can display a news item post to non-logged in users.
    // Don't display the mark read / unread controls in this case.
    if ($istracked && $CFG->forum_usermarksread && isloggedin()) {
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

    // Hack for allow to edit news posts those are not displayed yet until they are displayed
    $age = time() - $post->created;
    if (!$post->parent && $forum->type == 'news' && $discussion->timestart > time()) {
        $age = 0;
    }
    if (($ownpost && $age < $CFG->maxeditingtime) || $cm->cache->caps['mod/partforum:editanypost']) {
        $commands[] = array('url'=>new moodle_url('/mod/partforum/post.php', array('edit'=>$post->id)), 'text'=>$str->edit);
    }

    if ($cm->cache->caps['mod/partforum:splitdiscussions'] && $post->parent && $forum->type != 'single') {
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


    // Begin output

    $output  = '';

    if ($istracked) {
        if ($postisread) {
            $forumpostclass = ' read';
        } else {
            $forumpostclass = ' unread';
            $output .= html_writer::tag('a', '', array('name'=>'unread'));
        }
    } else {
        // ignore trackign status if not tracked or tracked param missing
        $forumpostclass = '';
    }

    $topicclass = '';
    if (empty($post->parent)) {
        $topicclass = ' firstpost starter';
    }

    $output .= html_writer::tag('a', '', array('id'=>'p'.$post->id));
    $output .= html_writer::start_tag('div', array('class'=>'forumpost clearfix'.$forumpostclass.$topicclass));
    $output .= html_writer::start_tag('div', array('class'=>'row header clearfix'));
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
    $output .= html_writer::tag('div', get_string('bynameondate', 'partforum', $by), array('class'=>'author'));

    $output .= html_writer::end_tag('div'); //topic
    $output .= html_writer::end_tag('div'); //row

    $output .= html_writer::start_tag('div', array('class'=>'row maincontent clearfix'));
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
    $output .= html_writer::start_tag('div', array('class'=>'options clearfix'));

    // Output ratings
    if (!empty($post->rating)) {
        $output .= html_writer::tag('div', $OUTPUT->render($post->rating), array('class'=>'forum-post-rating'));
    }

    // Output the commands
    $commandhtml = array();
    foreach ($commands as $command) {
        if (is_array($command)) {
            $commandhtml[] = html_writer::link($command['url'], $command['text']);
        } else {
            $commandhtml[] = $command;
        }
    }
    $output .= html_writer::tag('div', implode(' | ', $commandhtml), array('class'=>'commands'));

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
    $output .= html_writer::end_tag('div'); // forumpost

    // Mark the forum post as read if required
    if ($istracked && !$CFG->forum_usermarksread && !$postisread) {
        partforum_tp_mark_post_read($USER->id, $post, $forum->id);
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
 *            component => The component for this module - should always be mod_forum [required]
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

    // Check the component is mod_forum
    if ($params['component'] != 'mod_partforum') {
        throw new rating_exception('invalidcomponent');
    }

    // Check the ratingarea is post (the only rating area in forum)
    if ($params['ratingarea'] != 'post') {
        throw new rating_exception('invalidratingarea');
    }

    // Check the rateduserid is not the current user .. you can't rate your own posts
    if ($params['rateduserid'] == $USER->id) {
        throw new rating_exception('nopermissiontorate');
    }

    // Fetch all the related records ... we need to do this anyway to call forum_user_can_see_post
    $post = $DB->get_record('partforum_posts', array('id' => $params['itemid'], 'userid' => $params['rateduserid']), '*', MUST_EXIST);
    $discussion = $DB->get_record('partforum_discussions', array('id' => $post->discussion), '*', MUST_EXIST);
    $forum = $DB->get_record('partforum', array('id' => $discussion->forum), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('partforum', $forum->id, $course->id , false, MUST_EXIST);
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    // Make sure the context provided is the context of the forum
    if ($context->id != $params['context']->id) {
        throw new rating_exception('invalidcontext');
    }

    if ($forum->scale != $params['scaleid']) {
        //the scale being submitted doesnt match the one in the database
        throw new rating_exception('invalidscaleid');
    }

    // check the item we're rating was created in the assessable time window
    if (!empty($forum->assesstimestart) && !empty($forum->assesstimefinish)) {
        if ($post->created < $forum->assesstimestart || $post->created > $forum->assesstimefinish) {
            throw new rating_exception('notavailable');
        }
    }

    //check that the submitted rating is valid for the scale

    // lower limit
    if ($params['rating'] < 0  && $params['rating'] != RATING_UNSET_RATING) {
        throw new rating_exception('invalidnum');
    }

    // upper limit
    if ($forum->scale < 0) {
        //its a custom scale
        $scalerecord = $DB->get_record('scale', array('id' => -$forum->scale));
        if ($scalerecord) {
            $scalearray = explode(',', $scalerecord->scale);
            if ($params['rating'] > count($scalearray)) {
                throw new rating_exception('invalidnum');
            }
        } else {
            throw new rating_exception('invalidscaleid');
        }
    } else if ($params['rating'] > $forum->scale) {
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
    if (!partforum_user_can_see_post($forum, $discussion, $post, $USER, $cm)) {
        throw new rating_exception('nopermissiontorate');
    }

    return true;
}


/**
 * This function prints the overview of a discussion in the forum listing.
 * It needs some discussion information and some post information, these
 * happen to be combined for efficiency in the $post parameter by the function
 * that calls this one: forum_print_latest_discussions()
 *
 * @global object
 * @global object
 * @param object $post The post object (passed by reference for speed).
 * @param object $forum The forum object.
 * @param int $group Current group.
 * @param string $datestring Format to use for the dates.
 * @param boolean $cantrack Is tracking enabled for this forum.
 * @param boolean $forumtracked Is the user tracking this forum.
 * @param boolean $canviewparticipants True if user has the viewparticipants permission for this course
 */
function partforum_print_discussion_header(&$post, $forum, $group=-1, $datestring="",
                                        $cantrack=true, $forumtracked=true, $canviewparticipants=true, $modcontext=NULL) {

    global $USER, $CFG, $OUTPUT;

    static $rowcount;
    static $strmarkalldread;

    if (empty($modcontext)) {
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
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

    // Picture
    $postuser = new stdClass();
    $postuser->id = $post->userid;
    $postuser->firstname = $post->firstname;
    $postuser->lastname = $post->lastname;
    $postuser->imagealt = $post->imagealt;
    $postuser->picture = $post->picture;
    $postuser->email = $post->email;

    echo '<td class="picture">';
    echo $OUTPUT->user_picture($postuser, array('courseid'=>$forum->course));
    echo "</td>\n";

    // User name
    $fullname = fullname($post, has_capability('moodle/site:viewfullnames', $modcontext));
    echo '<td class="author">';
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->userid.'&amp;course='.$forum->course.'">'.$fullname.'</a>';
    echo "</td>\n";

    // Group picture
    if ($group !== -1) {  // Groups are active - group is a group data object or NULL
        echo '<td class="picture group">';
        if (!empty($group->picture) and empty($group->hidepicture)) {
            print_group_picture($group, $forum->course, false, false, true);
        } else if (isset($group->id)) {
            if($canviewparticipants) {
                echo '<a href="'.$CFG->wwwroot.'/user/index.php?id='.$forum->course.'&amp;group='.$group->id.'">'.$group->name.'</a>';
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
            if ($forumtracked) {
                if ($post->unread > 0) {
                    echo '<span class="unread">';
                    echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.'#unread">';
                    echo $post->unread;
                    echo '</a>';
                    echo '<a title="'.$strmarkalldread.'" href="'.$CFG->wwwroot.'/mod/partforum/markposts.php?f='.
                         $forum->id.'&amp;d='.$post->discussion.'&amp;mark=read&amp;returnpage=view.php">' .
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
    echo '<a href="'.$CFG->wwwroot.'/user/view.php?id='.$post->usermodified.'&amp;course='.$forum->course.'">'.
         fullname($usermodified).'</a><br />';
    echo '<a href="'.$CFG->wwwroot.'/mod/partforum/discuss.php?d='.$post->discussion.$parenturl.'">'.
          userdate($usedate, $datestring).'</a>';
    echo "</td>\n";

    echo "</tr>\n\n";

}


/**
 * Given a post object that we already know has a long message
 * this function truncates the message nicely to the first
 * sane place between $CFG->forum_longpost and $CFG->forum_shortpost
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
           if ($count > $CFG->forum_shortpost) {
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
 * @param int $id forum id if $forumtype is 'single',
 *              discussion id for any other forum type
 * @param mixed $mode forum layout mode
 * @param string $forumtype optional
 */
function partforum_print_mode_form($id, $mode, $forumtype='') {
    global $OUTPUT;
    if ($forumtype == 'single') {
        $select = new single_select(new moodle_url("/mod/partforum/view.php", array('f'=>$id)), 'mode', partforum_get_layout_modes(), $mode, null, "mode");
        $select->class = "forummode";
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

    $output  = '<div class="forumsearch">';
    $output .= '<form action="'.$CFG->wwwroot.'/mod/partforum/search.php" style="display:inline">';
    $output .= '<fieldset class="invisiblefieldset">';
    $output .= $OUTPUT->help_icon('search');
    $output .= '<label class="accesshide" for="search" >'.get_string('search', 'partforum').'</label>';
    $output .= '<input id="search" name="search" type="text" size="18" value="'.s($search, true).'" alt="search" />';
    $output .= '<label class="accesshide" for="searchforums" >'.get_string('searchforums', 'partforum').'</label>';
    $output .= '<input id="searchforums" value="'.get_string('searchforums', 'partforum').'" type="submit" />';
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
 * Given a discussion object that is being moved to $forumto,
 * this function checks all posts in that discussion
 * for attachments, and if any are found, these are
 * moved to the new forum directory.
 *
 * @global object
 * @param object $discussion
 * @param int $forumfrom source forum id
 * @param int $forumto target forum id
 * @return bool success
 */
function partforum_move_attachments($discussion, $forumfrom, $forumto) {
    global $DB;

    $fs = get_file_storage();

    $newcm = get_coursemodule_from_instance('partforum', $forumto);
    $oldcm = get_coursemodule_from_instance('partforum', $forumfrom);

    $newcontext = get_context_instance(CONTEXT_MODULE, $newcm->id);
    $oldcontext = get_context_instance(CONTEXT_MODULE, $oldcm->id);

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

    if (!$context = get_context_instance(CONTEXT_MODULE, $cm->id)) {
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
 * Serves the forum attachments. Implements needed access control ;-)
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

    if (!$forum = $DB->get_record('partforum', array('id'=>$cm->instance))) {
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
    if (!partforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
        return false;
    }


    // finally send the file
    send_stored_file($file, 0, 0, true); // download MUST be forced - security!
}

/**
 * If successful, this function returns the name of the file
 *
 * @global object
 * @param object $post is a full post record, including course and forum
 * @param object $forum
 * @param object $cm
 * @param mixed $mform
 * @param string $message
 * @return bool
 */
function partforum_add_attachment($post, $forum, $cm, $mform=null, &$message=null) {
    global $DB;

    if (empty($mform)) {
        return false;
    }

    if (empty($post->attachments)) {
        return true;   // Nothing to do
    }

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

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
    $forum      = $DB->get_record('partforum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('partforum', $forum->id);
    $context    = get_context_instance(CONTEXT_MODULE, $cm->id);

    $post->created    = $post->modified = time();
    $post->mailed     = "0";
    $post->userid     = $USER->id;
    $post->attachment = "";
	
	// update subject line for social comment
	if ($forum->type == 'participation' && $post->replytype==1){
		$tempstr_scom = ' - '.get_string('socialcomment', 'partforum');
		if (strpos($post->subject, $tempstr_scom) === false) {
			$post->subject .= $tempstr_scom;	
		}
	}

    $post->id = $DB->insert_record("partforum_posts", $post);
    $post->message = file_save_draft_area_files($post->itemid, $context->id, 'mod_partforum', 'post', $post->id, array('subdirs'=>true), $post->message);
    $DB->set_field('partforum_posts', 'message', $post->message, array('id'=>$post->id));
    partforum_add_attachment($post, $forum, $cm, $mform, $message);

    // Update discussion modified date
    $DB->set_field("partforum_discussions", "timemodified", $post->modified, array("id" => $post->discussion));
    $DB->set_field("partforum_discussions", "usermodified", $post->userid, array("id" => $post->discussion));

    if (partforum_tp_can_track_forums($forum) && partforum_tp_is_tracked($forum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->forum);
    }
    
    /*
    * PARTICIPATION FORUM - Automatic Rating
    * @author Thomas Lextrait thomas.lextrait@gmail.com
    *
    * Don't add a rating to posts that have a reply type of "social comment"
    * replytye[0] = 'substantive contribution'; replytype[1] = 'social comment'
    */
    if ($forum->type == 'participation' && $post->replytype==0){
		
		$partf_count = partforum_count_user_replies($forum->id, $USER->id);

		if($partf_count <= 1){ // The post is already posted so the count should be 1 for first post
			partforum_post_add_rating($context->id, $USER->id, $post->id, 6, 10);
		}else{
			partforum_post_add_rating($context->id, $USER->id, $post->id, 10, 10);
		}
		
		// Make sure grades are recorded (we pass the entire forum object to the method!)
		partforum_update_grades($forum);    
	}

    return $post->id;
}

/**
 * Counts the total number of replies created by a given user in
 * an entire forum. This counts the total number of posts that have
 * parents.
 * This function is used by the Participation Forum
 *
 * @author Thomas Lextrait thomas.lextrait@gmail.com
 *
 * @param $forum_id
 * @param $user_id
 * @return int
 */
function partforum_count_user_replies($forum_id, $user_id) {
	global $DB;
	
	$sql = "
		SELECT COUNT(post.id) AS postcount 
		FROM {partforum_posts} AS post, {partforum_discussions} AS disc
		WHERE post.userid=".$user_id." AND post.parent>0 AND disc.forum=".$forum_id." AND post.discussion=disc.id";
	
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
    $forum      = $DB->get_record('partforum', array('id' => $discussion->forum));
    $cm         = get_coursemodule_from_instance('partforum', $forum->id);
    $context    = get_context_instance(CONTEXT_MODULE, $cm->id);

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

    partforum_add_attachment($post, $forum, $cm, $mform, $message);

    if (partforum_tp_can_track_forums($forum) && partforum_tp_is_tracked($forum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->forum);
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

    $forum = $DB->get_record('partforum', array('id'=>$discussion->forum));
    $cm    = get_coursemodule_from_instance('partforum', $forum->id);

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
    $post->forum         = $forum->id;     // speedup
    $post->course        = $forum->course; // speedup
    $post->mailnow       = $discussion->mailnow;
    
    /*
    * PARTICIPATION FORUM - Add the intro text to post
    */
    if ($forum->type == 'participation'){
    	$post->message .= "<br/>" . $forum->intro . get_string("forumintro_default_partforum", "partforum", userdate($forum->assesstimefinish, get_string('strftimedate')));
    }

    $post->id = $DB->insert_record("partforum_posts", $post);

    // TODO: Fix the calling code so that there always is a $cm when this function is called
    if (!empty($cm->id) && !empty($discussion->itemid)) {   // In "single simple discussions" this may not exist yet
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
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
        partforum_add_attachment($post, $forum, $cm, $mform, $message);
    }

    if (partforum_tp_can_track_forums($forum) && partforum_tp_is_tracked($forum)) {
        partforum_tp_mark_post_read($post->userid, $post, $post->forum);
    }

    return $post->discussion;
}


/**
 * Deletes a discussion and handles all associated cleanup.
 *
 * @global object
 * @param object $discussion Discussion to delete
 * @param bool $fulldelete True when deleting entire forum
 * @param object $course Course
 * @param object $cm Course-module
 * @param object $forum Forum
 * @return bool
 */
function partforum_delete_discussion($discussion, $fulldelete, $course, $cm, $forum) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $result = true;

    if ($posts = $DB->get_records("partforum_posts", array("discussion" => $discussion->id))) {
        foreach ($posts as $post) {
            $post->course = $discussion->course;
            $post->forum  = $discussion->forum;
            if (!partforum_delete_post($post, 'ignore', $course, $cm, $forum, $fulldelete)) {
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
           ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $discussion->userid);
        }
    }

    return $result;
}


/**
 * Deletes a single forum post.
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
 * @param object $forum Forum
 * @param bool $skipcompletion True to skip updating completion state if it
 *   would otherwise be updated, i.e. when deleting entire forum anyway.
 * @return bool
 */
function partforum_delete_post($post, $children, $course, $cm, $forum, $skipcompletion=false) {
    global $DB, $CFG;
    require_once($CFG->libdir.'/completionlib.php');

    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

    if ($children != 'ignore' && ($childposts = $DB->get_records('partforum_posts', array('parent'=>$post->id)))) {
       if ($children) {
           foreach ($childposts as $childpost) {
               partforum_delete_post($childpost, true, $course, $cm, $forum, $skipcompletion);
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
    
    //update grades (for participation forum)
    partforum_update_grades($forum);

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
               ($forum->completiondiscussions || $forum->completionreplies || $forum->completionposts)) {
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
 * @param int $forumid
 * @param mixed $value
 * @return bool
 */
function partforum_forcesubscribe($forumid, $value=1) {
    global $DB;
    return $DB->set_field("partforum", "forcesubscribe", $value, array("id" => $forumid));
}

/**
 * @global object
 * @param object $forum
 * @return bool
 */
function partforum_is_forcesubscribed($forum) {
    global $DB;
    if (isset($forum->forcesubscribe)) {    // then we use that
        return ($forum->forcesubscribe == PARTFORUM_FORCESUBSCRIBE);
    } else {   // Check the database
       return ($DB->get_field('partforum', 'forcesubscribe', array('id' => $forum)) == PARTFORUM_FORCESUBSCRIBE);
    }
}

function partforum_get_forcesubscribed($forum) {
    global $DB;
    if (isset($forum->forcesubscribe)) {    // then we use that
        return $forum->forcesubscribe;
    } else {   // Check the database
        return $DB->get_field('partforum', 'forcesubscribe', array('id' => $forum));
    }
}

/**
 * @global object
 * @param int $userid
 * @param object $forum
 * @return bool
 */
function partforum_is_subscribed($userid, $forum) {
    global $DB;
    if (is_numeric($forum)) {
        $forum = $DB->get_record('partforum', array('id' => $forum));
    }
    if (partforum_is_forcesubscribed($forum)) {
        return true;
    }
    return $DB->record_exists("partforum_subscriptions", array("userid" => $userid, "forum" => $forum->id));
}

function partforum_get_subscribed_forums($course) {
    global $USER, $CFG, $DB;
    $sql = "SELECT f.id
              FROM {partforum} f
                   LEFT JOIN {partforum_subscriptions} fs ON (fs.forum = f.id AND fs.userid = ?)
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
 * @param int $forumid
 */
function partforum_subscribe($userid, $forumid) {
    global $DB;

    if ($DB->record_exists("partforum_subscriptions", array("userid"=>$userid, "forum"=>$forumid))) {
        return true;
    }

    $sub = new stdClass();
    $sub->userid  = $userid;
    $sub->forum = $forumid;

    return $DB->insert_record("partforum_subscriptions", $sub);
}

/**
 * Removes user from the subscriber list
 *
 * @global object
 * @param int $userid
 * @param int $forumid
 */
function partforum_unsubscribe($userid, $forumid) {
    global $DB;
    return $DB->delete_records("partforum_subscriptions", array("userid"=>$userid, "forum"=>$forumid));
}

/**
 * Given a new post, subscribes or unsubscribes as appropriate.
 * Returns some text which describes what happened.
 *
 * @global objec
 * @param object $post
 * @param object $forum
 */
function partforum_post_subscription($post, $forum) {

    global $USER;

    $action = '';
    $subscribed = partforum_is_subscribed($USER->id, $forum);

    if ($forum->forcesubscribe == PARTFORUM_FORCESUBSCRIBE) { // database ignored
        return "";

    } elseif (($forum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE)
        && !has_capability('moodle/course:manageactivities', get_context_instance(CONTEXT_COURSE, $forum->course), $USER->id)) {
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
    $info->forum = format_string($forum->name);

    switch ($action) {
        case 'subscribe':
            partforum_subscribe($USER->id, $post->forum);
            return "<p>".get_string("nowsubscribed", "partforum", $info)."</p>";
        case 'unsubscribe':
            partforum_unsubscribe($USER->id, $post->forum);
            return "<p>".get_string("nownotsubscribed", "partforum", $info)."</p>";
    }
}

/**
 * Generate and return the subscribe or unsubscribe link for a forum.
 *
 * @param object $forum the forum. Fields used are $forum->id and $forum->forcesubscribe.
 * @param object $context the context object for this forum.
 * @param array $messages text used for the link in its various states
 *      (subscribed, unsubscribed, forcesubscribed or cantsubscribe).
 *      Any strings not passed in are taken from the $defaultmessages array
 *      at the top of the function.
 * @param bool $cantaccessagroup
 * @param bool $fakelink
 * @param bool $backtoindex
 * @param array $subscribed_forums
 * @return string
 */
function partforum_get_subscribe_link($forum, $context, $messages = array(), $cantaccessagroup = false, $fakelink=true, $backtoindex=false, $subscribed_forums=null) {
    global $CFG, $USER, $PAGE, $OUTPUT;
    $defaultmessages = array(
        'subscribed' => get_string('unsubscribe', 'partforum'),
        'unsubscribed' => get_string('subscribe', 'partforum'),
        'cantaccessgroup' => get_string('no'),
        'forcesubscribed' => get_string('everyoneissubscribed', 'partforum'),
        'cantsubscribe' => get_string('disallowsubscribe','partforum')
    );
    $messages = $messages + $defaultmessages;

    if (partforum_is_forcesubscribed($forum)) {
        return $messages['forcesubscribed'];
    } else if ($forum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE && !has_capability('mod/partforum:managesubscriptions', $context)) {
        return $messages['cantsubscribe'];
    } else if ($cantaccessagroup) {
        return $messages['cantaccessgroup'];
    } else {
        if (!is_enrolled($context, $USER, '', true)) {
            return '';
        }
        if (is_null($subscribed_forums)) {
            $subscribed = partforum_is_subscribed($USER->id, $forum);
        } else {
            $subscribed = !empty($subscribed_forums[$forum->id]);
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
            $PAGE->requires->js('/mod/partforum/forum.js');
            $PAGE->requires->js_function_call('forum_produce_subscribe_link', array($forum->id, $backtoindexlink, $linktext, $linktitle));
            $link = "<noscript>";
        }
        $options['id'] = $forum->id;
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
 * Generate and return the track or no track link for a forum.
 *
 * @global object
 * @global object
 * @global object
 * @param object $forum the forum. Fields used are $forum->id and $forum->forcesubscribe.
 * @param array $messages
 * @param bool $fakelink
 * @return string
 */
function partforum_get_tracking_link($forum, $messages=array(), $fakelink=true) {
    global $CFG, $USER, $PAGE, $OUTPUT;

    static $strnotrackforum, $strtrackforum;

    if (isset($messages['trackforum'])) {
         $strtrackforum = $messages['trackforum'];
    }
    if (isset($messages['notrackforum'])) {
         $strnotrackforum = $messages['notrackforum'];
    }
    if (empty($strtrackforum)) {
        $strtrackforum = get_string('trackforum', 'partforum');
    }
    if (empty($strnotrackforum)) {
        $strnotrackforum = get_string('notrackforum', 'partforum');
    }

    if (partforum_tp_is_tracked($forum)) {
        $linktitle = $strnotrackforum;
        $linktext = $strnotrackforum;
    } else {
        $linktitle = $strtrackforum;
        $linktext = $strtrackforum;
    }

    $link = '';
    if ($fakelink) {
        $PAGE->requires->js('/mod/partforum/forum.js');
        $PAGE->requires->js_function_call('forum_produce_tracking_link', Array($forum->id, $linktext, $linktitle));
        // use <noscript> to print button in case javascript is not enabled
        $link .= '<noscript>';
    }
    $url = new moodle_url('/mod/partforum/settracking.php', array('id'=>$forum->id));
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
 * @param int $forumid
 * @param int $userid
 * @return bool
 */
function partforum_user_has_posted_discussion($forumid, $userid) {
    global $CFG, $DB;

    $sql = "SELECT 'x'
              FROM {partforum_discussions} d, {partforum_posts} p
             WHERE d.forum = ? AND p.discussion = d.id AND p.parent = 0 and p.userid = ?";

    return $DB->record_exists_sql($sql, array($forumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 * @return array
 */
function partforum_discussions_user_has_posted_in($forumid, $userid) {
    global $CFG, $DB;

    $haspostedsql = "SELECT d.id AS id,
                            d.*
                       FROM {partforum_posts} p,
                            {partforum_discussions} d
                      WHERE p.discussion = d.id
                        AND d.forum = ?
                        AND p.userid = ?";

    return $DB->get_records_sql($haspostedsql, array($forumid, $userid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $did
 * @param int $userid
 * @return bool
 */
function partforum_user_has_posted($forumid, $did, $userid) {
    global $DB;

    if (empty($did)) {
        // posted in any forum discussion?
        $sql = "SELECT 'x'
                  FROM {partforum_posts} p
                  JOIN {partforum_discussions} d ON d.id = p.discussion
                 WHERE p.userid = :userid AND d.forum = :forumid";
        return $DB->record_exists_sql($sql, array('forumid'=>$forumid,'userid'=>$userid));
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
 * @param object $forum
 * @param object $currentgroup
 * @param int $unused
 * @param object $cm
 * @param object $context
 * @return bool
 */
function partforum_user_can_post_discussion($forum, $currentgroup=null, $unused=-1, $cm=NULL, $context=NULL) {
// $forum is an object
    global $USER;

    // shortcut - guest and not-logged-in users can not post
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    if (!$cm) {
        debugging('missing cm', DEBUG_DEVELOPER);
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$context) {
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    if ($currentgroup === null) {
        $currentgroup = groups_get_activity_group($cm);
    }

    $groupmode = groups_get_activity_groupmode($cm);

    if ($forum->type == 'news') {
        $capname = 'mod/partforum:addnews';
    } else {
        $capname = 'mod/partforum:startdiscussion';
    }

    if (!has_capability($capname, $context)) {
        return false;
    }

    if ($forum->type == 'eachuser') {
        if (partforum_user_has_posted_discussion($forum->id, $USER->id)) {
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
 * This function checks whether the user can reply to posts in a forum
 * discussion. Use forum_user_can_post_discussion() to check whether the user
 * can start discussions.
 *
 * @global object
 * @global object
 * @uses DEBUG_DEVELOPER
 * @uses CONTEXT_MODULE
 * @uses VISIBLEGROUPS
 * @param object $forum forum object
 * @param object $discussion
 * @param object $user
 * @param object $cm
 * @param object $course
 * @param object $context
 * @return bool
 */
function partforum_user_can_post($forum, $discussion, $user=NULL, $cm=NULL, $course=NULL, $context=NULL) {
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
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    if (!$course) {
        debugging('missing course', DEBUG_DEVELOPER);
        if (!$course = $DB->get_record('course', array('id' => $forum->course))) {
            print_error('invalidcourseid');
        }
    }

    if (!$context) {
        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
    }

    // normal users with temporary guest access can not post, suspended users can not post either
    if (!is_viewing($context, $user->id) and !is_enrolled($context, $user->id, '', true)) {
        return false;
    }

    if ($forum->type == 'news') {
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
 * @param object $forum
 * @param object $discussion
 * @param object $user
 */
function partforum_user_can_view_post($post, $course, $cm, $forum, $discussion, $user=NULL){

    global $CFG, $USER;

    if (!$user){
        $user = $USER;
    }

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
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
 * @param object $forum
 * @param object $discussion
 * @param object $context
 * @param object $user
 * @return bool
 */
function partforum_user_can_see_discussion($forum, $discussion, $context, $user=NULL) {
    global $USER, $DB;

    if (empty($user) || empty($user->id)) {
        $user = $USER;
    }

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('partforum',array('id'=>$forum))) {
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

    if ($forum->type == 'qanda' &&
            !partforum_user_has_posted($forum->id, $discussion->id, $user->id) &&
            !has_capability('mod/partforum:viewqandawithoutposting', $context)) {
        return false;
    }
    return true;
}


/**
 * @global object
 * @global object
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $user
 * @param object $cm
 * @return bool
 */
function partforum_user_can_see_post($forum, $discussion, $post, $user=NULL, $cm=NULL) {
    global $CFG, $USER, $DB;

    // retrieve objects (yuk)
    if (is_numeric($forum)) {
        debugging('missing full forum', DEBUG_DEVELOPER);
        if (!$forum = $DB->get_record('partforum',array('id'=>$forum))) {
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
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
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
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $user->id)) {
            return false;
        }
    }

    if (isset($cm->uservisible)) {
        if (!$cm->uservisible) {
            return false;
        }
    } else {
        if (!coursemodule_visible_for_user($cm, $user->id)) {
            return false;
        }
    }

    if ($forum->type == 'qanda') {
        $firstpost = partforum_get_firstpost_from_discussion($discussion->id);
        $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
        $userfirstpost = partforum_get_user_posted_time($discussion->id, $user->id);

        return (($userfirstpost !== false && (time() - $userfirstpost >= $CFG->maxeditingtime)) ||
                $firstpost->id == $post->id || $post->userid == $user->id || $firstpost->userid == $user->id ||
                has_capability('mod/partforum:viewqandawithoutposting', $modcontext, $user->id, false));
    }
    return true;
}


/**
 * Prints the discussion view screen for a forum.
 *
 * @global object
 * @global object
 * @param object $course The current course object.
 * @param object $forum Forum to be printed.
 * @param int $maxdiscussions .
 * @param string $displayformat The display format to use (optional).
 * @param string $sort Sort arguments for database query (optional).
 * @param int $groupmode Group mode of the forum (optional).
 * @param void $unused (originally current group)
 * @param int $page Page mode, page to display (optional).
 * @param int $perpage The maximum number of discussions per page(optional)
 *
 */
function partforum_print_latest_discussions($course, $forum, $maxdiscussions=-1, $displayformat='plain', $sort='',
                                        $currentgroup=-1, $groupmode=-1, $page=-1, $perpage=100, $cm=NULL) {
    global $CFG, $USER, $OUTPUT;

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }
    $context = get_context_instance(CONTEXT_MODULE, $cm->id);

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

    $canstart = partforum_user_can_post_discussion($forum, $currentgroup, $groupmode, $cm, $context);
    if (!$canstart and $forum->type !== 'news') {
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
        echo '<div class="singlebutton forumaddnew">';
        echo "<form id=\"newdiscussionform\" method=\"get\" action=\"$CFG->wwwroot/mod/partforum/post.php\">";
        echo '<div>';
        echo "<input type=\"hidden\" name=\"forum\" value=\"$forum->id\" />";
        switch ($forum->type) {
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

    } else if (isguestuser() or !isloggedin() or $forum->type == 'news') {
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
        echo '<div class="forumnodiscuss">';
        if ($forum->type == 'news') {
            echo '('.get_string('nonews', 'partforum').')';
        } else if ($forum->type == 'qanda') {
            echo '('.get_string('noquestions','partforum').')';
        } else if ($forum->type == 'participation') {
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
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$forum->id");
        if ($numdiscussions > 1000) {
            // saves some memory on sites with very large forums
            $replies = partforum_count_discussion_replies($forum->id, $sort, $maxdiscussions, $page, $perpage);
        } else {
            $replies = partforum_count_discussion_replies($forum->id);
        }

    } else {
        $replies = partforum_count_discussion_replies($forum->id);

        if ($maxdiscussions > 0 and $maxdiscussions <= count($discussions)) {
            $olddiscussionlink = true;
        }
    }

    $canviewparticipants = has_capability('moodle/course:viewparticipants',$context);

    $strdatestring = get_string('strftimerecentfull');

    // Check if the forum is tracked.
    if ($cantrack = partforum_tp_can_track_forums($forum)) {
        $forumtracked = partforum_tp_is_tracked($forum);
    } else {
        $forumtracked = false;
    }

    if ($forumtracked) {
        $unreads = partforum_get_discussions_unread($cm);
    } else {
        $unreads = array();
    }

    if ($displayformat == 'header') {
        echo '<table cellspacing="0" class="forumheaderlist">';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="header topic" scope="col">'.get_string('discussion', 'partforum').'</th>';
        echo '<th class="header author" colspan="2" scope="col">'.get_string('startedby', 'partforum').'</th>';
        if ($groupmode > 0) {
            echo '<th class="header group" scope="col">'.get_string('group').'</th>';
        }
        if (has_capability('mod/partforum:viewdiscussion', $context)) {
            echo '<th class="header replies" scope="col">'.get_string('replies', 'partforum').'</th>';
            // If the forum can be tracked, display the unread column.
            if ($cantrack) {
                echo '<th class="header replies" scope="col">'.get_string('unread', 'partforum');
                if ($forumtracked) {
                    echo '&nbsp;<a title="'.get_string('markallread', 'partforum').
                         '" href="'.$CFG->wwwroot.'/mod/partforum/markposts.php?f='.
                         $forum->id.'&amp;mark=read&amp;returnpage=view.php">'.
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
        if (!$forumtracked) {
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
                partforum_print_discussion_header($discussion, $forum, $group, $strdatestring, $cantrack, $forumtracked,
                    $canviewparticipants, $context);
            break;
            default:
                $link = false;

                if ($discussion->replies) {
                    $link = true;
                } else {
                    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $link = partforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
                }

                $discussion->forum = $forum->id;

                partforum_print_post($discussion, $discussion, $forum, $cm, $course, $ownpost, 0, $link, false);
            break;
        }
    }

    if ($displayformat == "header") {
        echo '</tbody>';
        echo '</table>';
    }

    if ($olddiscussionlink) {
        if ($forum->type == 'news') {
            $strolder = get_string('oldertopics', 'partforum');
        } else {
            $strolder = get_string('olderdiscussions', 'partforum');
        }
        echo '<div class="forumolddiscuss">';
        echo '<a href="'.$CFG->wwwroot.'/mod/partforum/view.php?f='.$forum->id.'&amp;showall=1">';
        echo $strolder.'</a> ...</div>';
    }

    if ($page != -1) { ///Show the paging bar
        echo $OUTPUT->paging_bar($numdiscussions, $page, $perpage, "view.php?f=$forum->id");
    }
}


/**
 * Prints a forum discussion
 *
 * @uses CONTEXT_MODULE
 * @uses PARTFORUM_MODE_FLATNEWEST
 * @uses PARTFORUM_MODE_FLATOLDEST
 * @uses PARTFORUM_MODE_THREADED
 * @uses PARTFORUM_MODE_NESTED
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $forum
 * @param stdClass $discussion
 * @param stdClass $post
 * @param int $mode
 * @param mixed $canreply
 * @param bool $canrate
 */
function partforum_print_discussion($course, $cm, $forum, $discussion, $post, $mode, $canreply=NULL, $canrate=false) {
    global $USER, $CFG;

    require_once($CFG->dirroot.'/rating/lib.php');

    $ownpost = (isloggedin() && $USER->id == $post->userid);

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    if ($canreply === NULL) {
        $reply = partforum_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);
    } else {
        $reply = $canreply;
    }

    // $cm holds general cache for forum functions
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

    $forumtracked = partforum_tp_is_tracked($forum);
    $posts = partforum_get_all_discussion_posts($discussion->id, $sort, $forumtracked);
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
    if ($forum->assessed!=RATING_AGGREGATE_NONE && $forum->type!='participation') {
        $ratingoptions = new stdClass;
        $ratingoptions->context = $modcontext;
        $ratingoptions->component = 'mod_partforum';
        $ratingoptions->ratingarea = 'post';
        $ratingoptions->items = $posts;
        $ratingoptions->aggregate = $forum->assessed;//the aggregation method
        $ratingoptions->scaleid = $forum->scale;
        $ratingoptions->userid = $USER->id;
        if ($forum->type == 'single' or !$discussion->id) {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/partforum/view.php?id=$cm->id";
        } else {
            $ratingoptions->returnurl = "$CFG->wwwroot/mod/partforum/discuss.php?d=$discussion->id";
        }
        $ratingoptions->assesstimestart = $forum->assesstimestart;
        $ratingoptions->assesstimefinish = $forum->assesstimefinish;

        $rm = new rating_manager();
        $posts = $rm->get_ratings($ratingoptions);
    }


    $post->forum = $forum->id;   // Add the forum id to the post object, later used by partforum_print_post
    $post->forumtype = $forum->type;

    $post->subject = format_string($post->subject);

    $postread = !empty($post->postread);

    partforum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, false,
                         '', '', $postread, true, $forumtracked);

    switch ($mode) {
        case PARTFORUM_MODE_FLATOLDEST :
        case PARTFORUM_MODE_FLATNEWEST :
        default:
            partforum_print_posts_flat($course, $cm, $forum, $discussion, $post, $mode, $reply, $forumtracked, $posts);
            break;

        case PARTFORUM_MODE_THREADED :
            partforum_print_posts_threaded($course, $cm, $forum, $discussion, $post, 0, $reply, $forumtracked, $posts);
            break;

        case PARTFORUM_MODE_NESTED :
            partforum_print_posts_nested($course, $cm, $forum, $discussion, $post, $reply, $forumtracked, $posts);
            break;
    }
}


/**
 * @global object
 * @global object
 * @uses PARTFORUM_MODE_FLATNEWEST
 * @param object $course
 * @param object $cm
 * @param object $forum
 * @param object $discussion
 * @param object $post
 * @param object $mode
 * @param bool $reply
 * @param bool $forumtracked
 * @param array $posts
 * @return void
 */
function partforum_print_posts_flat($course, &$cm, $forum, $discussion, $post, $mode, $reply, $forumtracked, $posts) {
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

        partforum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link,
                             '', '', $postread, true, $forumtracked);
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
function partforum_print_posts_threaded($course, &$cm, $forum, $discussion, $parent, $depth, $reply, $forumtracked, $posts) {
    global $USER, $CFG;

    $link  = false;

    if (!empty($posts[$parent->id]->children)) {
        $posts = $posts[$parent->id]->children;

        $modcontext       = get_context_instance(CONTEXT_MODULE, $cm->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $modcontext);

        foreach ($posts as $post) {

            echo '<div class="indent">';
            if ($depth > 0) {
                $ownpost = ($USER->id == $post->userid);
                $post->subject = format_string($post->subject);

                $postread = !empty($post->postread);

                partforum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link,
                                     '', '', $postread, true, $forumtracked);
            } else {
                if (!partforum_user_can_see_post($forum, $discussion, $post, NULL, $cm)) {
                    echo "</div>\n";
                    continue;
                }
                $by = new stdClass();
                $by->name = fullname($post, $canviewfullnames);
                $by->date = userdate($post->modified);

                if ($forumtracked) {
                    if (!empty($post->postread)) {
                        $style = '<span class="forumthread read">';
                    } else {
                        $style = '<span class="forumthread unread">';
                    }
                } else {
                    $style = '<span class="forumthread">';
                }
                echo $style."<a name=\"$post->id\"></a>".
                     "<a href=\"discuss.php?d=$post->discussion&amp;parent=$post->id\">".format_string($post->subject,true)."</a> ";
                print_string("bynameondate", "partforum", $by);
                echo "</span>";
            }

            partforum_print_posts_threaded($course, $cm, $forum, $discussion, $post, $depth-1, $reply, $forumtracked, $posts);
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
function partforum_print_posts_nested($course, &$cm, $forum, $discussion, $parent, $reply, $forumtracked, $posts) {
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

            partforum_print_post($post, $discussion, $forum, $cm, $course, $ownpost, $reply, $link,
                                 '', '', $postread, true, $forumtracked);
            partforum_print_posts_nested($course, $cm, $forum, $discussion, $post, $reply, $forumtracked, $posts);
            echo "</div>\n";
        }
    }
}

/**
 * Returns all forum posts since a given time in specified forum.
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

    if (!$posts = $DB->get_records_sql("SELECT p.*, f.type AS forumtype, d.forum, d.groupid,
                                              d.timestart, d.timeend, d.userid AS duserid,
                                              u.firstname, u.lastname, u.email, u.picture, u.imagealt, u.email
                                         FROM {partforum_posts} p
                                              JOIN {partforum_discussions} d ON d.id = p.discussion
                                              JOIN {partforum} f             ON f.id = d.forum
                                              JOIN {user} u              ON u.id = p.userid
                                              $groupjoin
                                        WHERE p.created > ? AND f.id = ?
                                              $userselect $groupselect
                                     ORDER BY p.id ASC", $params)) { // order by initial posting date
         return;
    }

    $groupmode       = groups_get_activity_groupmode($cm, $course);
    $cm_context      = get_context_instance(CONTEXT_MODULE, $cm->id);
    $viewhiddentimed = has_capability('mod/partforum:viewhiddentimedposts', $cm_context);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $cm_context);

    if (is_null($modinfo->groups)) {
        $modinfo->groups = groups_get_user_groups($course->id); // load all my groups and cache it in modinfo
    }

    $printposts = array();
    foreach ($posts as $post) {

        if (!empty($CFG->forum_enabletimedposts) and $USER->id != $post->duserid
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

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

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
 * @param int $forumid
 * @return string
 */
function partforum_update_subscriptions_button($courseid, $forumid) {
    global $CFG, $USER;

    if (!empty($USER->subscriptionsediting)) {
        $string = get_string('turneditingoff');
        $edit = "off";
    } else {
        $string = get_string('turneditingon');
        $edit = "on";
    }

    return "<form method=\"get\" action=\"$CFG->wwwroot/mod/partforum/subscribers.php\">".
           "<input type=\"hidden\" name=\"id\" value=\"$forumid\" />".
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
    $context = get_context_instance(CONTEXT_COURSE, $cp->courseid);
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
        $context = get_context_instance(CONTEXT_COURSE, $cp->courseid);
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
                 $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                 partforum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             break;

        case CONTEXT_COURSECAT:   // For a whole category
             $rs = $DB->get_recordset('course', array('category' => $context->instanceid),'','id');
             foreach ($rs as $course) {
                 $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                 partforum_add_user_default_subscriptions($userid, $subcontext);
             }
             $rs->close();
             if ($categories = $DB->get_records('course_categories', array('parent' => $context->instanceid))) {
                 foreach ($categories as $category) {
                     $subcontext = get_context_instance(CONTEXT_COURSECAT, $category->id);
                     partforum_add_user_default_subscriptions($userid, $subcontext);
                 }
             }
             break;


        case CONTEXT_COURSE:   // For a whole course
             if (is_enrolled($context, $userid)) {
                if ($course = $DB->get_record('course', array('id' => $context->instanceid))) {
                     if ($forums = get_all_instances_in_course('partforum', $course, $userid, false)) {
                         foreach ($forums as $forum) {
                             if ($forum->forcesubscribe != PARTFORUM_INITIALSUBSCRIBE) {
                                 continue;
                             }
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                                 if (has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                     partforum_subscribe($userid, $forum->id);
                                 }
                             }
                         }
                     }
                 }
             }
             break;

        case CONTEXT_MODULE:   // Just one forum
            if (has_capability('mod/partforum:initialsubscriptions', $context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($forum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if ($forum->forcesubscribe != PARTFORUM_INITIALSUBSCRIBE) {
                             continue;
                         }
                         if (has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                             partforum_subscribe($userid, $forum->id);
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
            // find all courses in which this user has a forum subscription
            if ($courses = $DB->get_records_sql("SELECT c.id
                                                  FROM {course} c,
                                                       {partforum_subscriptions} fs,
                                                       {partforum} f
                                                       WHERE c.id = f.course AND f.id = fs.forum AND fs.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                foreach ($courses as $course) {
                    $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                    partforum_remove_user_subscriptions($userid, $subcontext);
                }
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
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
                    // find all forums in which this user has a subscription, and its coursemodule id
                    if ($forums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                         FROM {partforum} f,
                                                              {modules} m,
                                                              {course_modules} cm,
                                                              {partforum_subscriptions} fs
                                                        WHERE fs.userid = ? AND f.course = ?
                                                              AND fs.forum = f.id AND cm.instance = f.id
                                                              AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($forums as $forum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                     partforum_unsubscribe($userid, $forum->id);
                                 }
                             }
                         }
                     }
                 }
            }
            break;

        case CONTEXT_MODULE:   // Just one forum
            if (!is_enrolled($context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($forum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if (!has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                             partforum_unsubscribe($userid, $forum->id);
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
                                                       WHERE c.id = f.course AND f.id = fr.forumid AND fr.userid = ?
                                                       GROUP BY c.id", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            if ($courses = $DB->get_records_sql("SELECT c.id
                                              FROM {course} c,
                                                   {partforum_track_prefs} ft,
                                                   {partforum} f
                                             WHERE c.id = f.course AND f.id = ft.forumid AND ft.userid = ?", array($userid))) {

                $allcourses = $allcourses + $courses;
            }
            foreach ($allcourses as $course) {
                $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
                partforum_remove_user_tracking($userid, $subcontext);
            }
            break;

        case CONTEXT_COURSECAT:   // For a whole category
             if ($courses = $DB->get_records('course', array('category' => $context->instanceid), '', 'id')) {
                 foreach ($courses as $course) {
                     $subcontext = get_context_instance(CONTEXT_COURSE, $course->id);
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
                    // find all forums in which this user has reading tracked
                    if ($forums = $DB->get_records_sql("SELECT DISTINCT f.id, cm.id as coursemodule
                                                     FROM {partforum} f,
                                                          {modules} m,
                                                          {course_modules} cm,
                                                          {partforum_read} fr
                                                    WHERE fr.userid = ? AND f.course = ?
                                                          AND fr.forumid = f.id AND cm.instance = f.id
                                                          AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($forums as $forum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                    partforum_tp_delete_read_records($userid, -1, -1, $forum->id);
                                 }
                             }
                         }
                     }

                    // find all forums in which this user has a disabled tracking
                    if ($forums = $DB->get_records_sql("SELECT f.id, cm.id as coursemodule
                                                     FROM {partforum} f,
                                                          {modules} m,
                                                          {course_modules} cm,
                                                          {partforum_track_prefs} ft
                                                    WHERE ft.userid = ? AND f.course = ?
                                                          AND ft.forumid = f.id AND cm.instance = f.id
                                                          AND cm.module = m.id AND m.name = 'partforum'", array($userid, $context->instanceid))) {

                         foreach ($forums as $forum) {
                             if ($modcontext = get_context_instance(CONTEXT_MODULE, $forum->coursemodule)) {
                                 if (!has_capability('mod/partforum:viewdiscussion', $modcontext, $userid)) {
                                    $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'forumid' => $forum->id));
                                 }
                             }
                         }
                     }
                 }
            }
            break;

        case CONTEXT_MODULE:   // Just one forum
            if (!is_enrolled($context, $userid)) {
                 if ($cm = get_coursemodule_from_id('partforum', $context->instanceid)) {
                     if ($forum = $DB->get_record('partforum', array('id' => $cm->instance))) {
                         if (!has_capability('mod/partforum:viewdiscussion', $context, $userid)) {
                            $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'forumid' => $forum->id));
                            partforum_tp_delete_read_records($userid, -1, -1, $forum->id);
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

    if (!partforum_tp_can_track_forums(false, $user)) {
        return true;
    }

    $status = true;

    $now = time();
    $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);

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

        $sql = "INSERT INTO {partforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
                  FROM {partforum_posts} p
                       JOIN {partforum_discussions} d       ON d.id = p.discussion
                       JOIN {partforum} f                   ON f.id = d.forum
                       LEFT JOIN {partforum_track_prefs} tf ON (tf.userid = ? AND tf.forumid = f.id)
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
    $cutoffdate = $now - ($CFG->forum_oldpostdays * 24 * 3600);

    if (!$DB->record_exists('partforum_read', array('userid' => $userid, 'postid' => $postid))) {
        $sql = "INSERT INTO {partforum_read} (userid, postid, discussionid, forumid, firstread, lastread)

                SELECT ?, p.id, p.discussion, d.forum, ?, ?
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
 * Returns all records in the 'forum_read' table matching the passed keys, indexed
 * by userid.
 *
 * @global object
 * @param int $userid
 * @param int $postid
 * @param int $discussionid
 * @param int $forumid
 * @return array
 */
function partforum_tp_get_read_records($userid=-1, $postid=-1, $discussionid=-1, $forumid=-1) {
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
    if ($forumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'forumid = ?';
        $params[] = $forumid;
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
function partforum_tp_mark_post_read($userid, $post, $forumid) {
    if (!partforum_tp_is_post_old($post)) {
        return partforum_tp_add_read_record($userid, $post->id);
    } else {
        return true;
    }
}

/**
 * Marks a whole forum as read, for a given user
 *
 * @global object
 * @global object
 * @param object $user
 * @param int $forumid
 * @param int|bool $groupid
 * @return bool
 */
function partforum_tp_mark_forum_read($user, $forumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    $groupsel = "";
    $params = array($user->id, $forumid, $cutoffdate);

    if ($groupid !== false) {
        $groupsel = " AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT p.id
              FROM {partforum_posts} p
                   LEFT JOIN {partforum_discussions} d ON d.id = p.discussion
                   LEFT JOIN {partforum_read} r        ON (r.postid = p.id AND r.userid = ?)
             WHERE d.forum = ?
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

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

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
    return ($post->modified < ($time - ($CFG->forum_oldpostdays * 24 * 3600)));
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

    $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;

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

    $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;

    $sql = 'SELECT COUNT(p.id) '.
           'FROM {partforum_posts} p '.
           'LEFT JOIN {partforum_read} r ON r.postid = p.id AND r.userid = ? '.
           'WHERE p.discussion = ? '.
                'AND p.modified >= ? AND r.id is NULL';

    return $DB->count_records_sql($sql, array($userid, $cutoffdate, $discussionid));
}

/**
 * Returns the count of posts for the provided forum and [optionally] group.
 * @global object
 * @global object
 * @param int $forumid
 * @param int|bool $groupid
 * @return int
 */
function partforum_tp_count_forum_posts($forumid, $groupid=false) {
    global $CFG, $DB;
    $params = array($forumid);
    $sql = 'SELECT COUNT(*) '.
           'FROM {partforum_posts} fp,{partforum_discussions} fd '.
           'WHERE fd.forum = ? AND fp.discussion = fd.id';
    if ($groupid !== false) {
        $sql .= ' AND (fd.groupid = ? OR fd.groupid = -1)';
        $params[] = $groupid;
    }
    $count = $DB->count_records_sql($sql, $params);


    return $count;
}

/**
 * Returns the count of records for the provided user and forum and [optionally] group.
 * @global object
 * @global object
 * @param int $userid
 * @param int $forumid
 * @param int|bool $groupid
 * @return int
 */
function partforum_tp_count_forum_read_records($userid, $forumid, $groupid=false) {
    global $CFG, $DB;

    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

    $groupsel = '';
    $params = array($userid, $forumid, $cutoffdate);
    if ($groupid !== false) {
        $groupsel = "AND (d.groupid = ? OR d.groupid = -1)";
        $params[] = $groupid;
    }

    $sql = "SELECT COUNT(p.id)
              FROM  {partforum_posts} p
                    JOIN {partforum_discussions} d ON d.id = p.discussion
                    LEFT JOIN {partforum_read} r   ON (r.postid = p.id AND r.userid= ?)
              WHERE d.forum = ?
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
    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);
    $params = array($userid, $userid, $courseid, $cutoffdate);

    if (!empty($CFG->forum_enabletimedposts)) {
        $timedsql = "AND d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)";
        $params[] = $now;
        $params[] = $now;
    } else {
        $timedsql = "";
    }

    $sql = "SELECT f.id, COUNT(p.id) AS unread
              FROM {partforum_posts} p
                   JOIN {partforum_discussions} d       ON d.id = p.discussion
                   JOIN {partforum} f                   ON f.id = d.forum
                   JOIN {course} c                  ON c.id = f.course
                   LEFT JOIN {partforum_read} r         ON (r.postid = p.id AND r.userid = ?)
                   LEFT JOIN {partforum_track_prefs} tf ON (tf.userid = ? AND tf.forumid = f.id)
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
 * Returns the count of records for the provided user and forum and [optionally] group.
 *
 * @global object
 * @global object
 * @global object
 * @param object $cm
 * @param object $course
 * @return int
 */
function partforum_tp_count_forum_unread_posts($cm, $course) {
    global $CFG, $USER, $DB;

    static $readcache = array();

    $forumid = $cm->instance;

    if (!isset($readcache[$course->id])) {
        $readcache[$course->id] = array();
        if ($counts = partforum_tp_get_course_unread_posts($USER->id, $course->id)) {
            foreach ($counts as $count) {
                $readcache[$course->id][$count->id] = $count->unread;
            }
        }
    }

    if (empty($readcache[$course->id][$forumid])) {
        // no need to check group mode ;-)
        return 0;
    }

    $groupmode = groups_get_activity_groupmode($cm, $course);

    if ($groupmode != SEPARATEGROUPS) {
        return $readcache[$course->id][$forumid];
    }

    if (has_capability('moodle/site:accessallgroups', get_context_instance(CONTEXT_MODULE, $cm->id))) {
        return $readcache[$course->id][$forumid];
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
    $cutoffdate = $now - ($CFG->forum_oldpostdays*24*60*60);
    $params = array($USER->id, $forumid, $cutoffdate);

    if (!empty($CFG->forum_enabletimedposts)) {
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
             WHERE d.forum = ?
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
 * @param int $forumid
 * @return bool
 */
function partforum_tp_delete_read_records($userid=-1, $postid=-1, $discussionid=-1, $forumid=-1) {
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
    if ($forumid > -1) {
        if ($select != '') $select .= ' AND ';
        $select .= 'forumid = ?';
        $params[] = $forumid;
    }
    if ($select == '') {
        return false;
    }
    else {
        return $DB->delete_records_select('partforum_read', $select, $params);
    }
}
/**
 * Get a list of forums not tracked by the user.
 *
 * @global object
 * @global object
 * @param int $userid The id of the user to use.
 * @param int $courseid The id of the course being checked.
 * @return mixed An array indexed by forum id, or false.
 */
function partforum_tp_get_untracked_forums($userid, $courseid) {
    global $CFG, $DB;

    $sql = "SELECT f.id
              FROM {partforum} f
                   LEFT JOIN {partforum_track_prefs} ft ON (ft.forumid = f.id AND ft.userid = ?)
             WHERE f.course = ?
                   AND (f.trackingtype = ".PARTFORUM_TRACKING_OFF."
                        OR (f.trackingtype = ".PARTFORUM_TRACKING_OPTIONAL." AND ft.id IS NOT NULL))";

    if ($forums = $DB->get_records_sql($sql, array($userid, $courseid))) {
        foreach ($forums as $forum) {
            $forums[$forum->id] = $forum;
        }
        return $forums;

    } else {
        return array();
    }
}

/**
 * Determine if a user can track forums and optionally a particular forum.
 * Checks the site settings, the user settings and the forum settings (if
 * requested).
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $forum The forum object to test, or the int id (optional).
 * @param mixed $userid The user object to check for (optional).
 * @return boolean
 */
function partforum_tp_can_track_forums($forum=false, $user=false) {
    global $USER, $CFG, $DB;

    // if possible, avoid expensive
    // queries
    if (empty($CFG->forum_trackreadposts)) {
        return false;
    }

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    if ($forum === false) {
        // general abitily to track forums
        return (bool)$user->trackforums;
    }


    // Work toward always passing an object...
    if (is_numeric($forum)) {
        debugging('Better use proper forum object.', DEBUG_DEVELOPER);
        $forum = $DB->get_record('partforum', array('id' => $forum), '', 'id,trackingtype');
    }

    $forumallows = ($forum->trackingtype == PARTFORUM_TRACKING_OPTIONAL);
    $forumforced = ($forum->trackingtype == PARTFORUM_TRACKING_ON);

    return ($forumforced || $forumallows)  && !empty($user->trackforums);
}

/**
 * Tells whether a specific forum is tracked by the user. A user can optionally
 * be specified. If not specified, the current user is assumed.
 *
 * @global object
 * @global object
 * @global object
 * @param mixed $forum If int, the id of the forum being checked; if object, the forum object
 * @param int $userid The id of the user being checked (optional).
 * @return boolean
 */
function partforum_tp_is_tracked($forum, $user=false) {
    global $USER, $CFG, $DB;

    if ($user === false) {
        $user = $USER;
    }

    if (isguestuser($user) or empty($user->id)) {
        return false;
    }

    // Work toward always passing an object...
    if (is_numeric($forum)) {
        debugging('Better use proper forum object.', DEBUG_DEVELOPER);
        $forum = $DB->get_record('partforum', array('id' => $forum));
    }

    if (!partforum_tp_can_track_forums($forum, $user)) {
        return false;
    }

    $forumallows = ($forum->trackingtype == PARTFORUM_TRACKING_OPTIONAL);
    $forumforced = ($forum->trackingtype == PARTFORUM_TRACKING_ON);

    return $forumforced ||
           ($forumallows && $DB->get_record('partforum_track_prefs', array('userid' => $user->id, 'forumid' => $forum->id)) === false);
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 */
function partforum_tp_start_tracking($forumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    return $DB->delete_records('partforum_track_prefs', array('userid' => $userid, 'forumid' => $forumid));
}

/**
 * @global object
 * @global object
 * @param int $forumid
 * @param int $userid
 */
function partforum_tp_stop_tracking($forumid, $userid=false) {
    global $USER, $DB;

    if ($userid === false) {
        $userid = $USER->id;
    }

    if (!$DB->record_exists('partforum_track_prefs', array('userid' => $userid, 'forumid' => $forumid))) {
        $track_prefs = new stdClass();
        $track_prefs->userid = $userid;
        $track_prefs->forumid = $forumid;
        $DB->insert_record('partforum_track_prefs', $track_prefs);
    }

    return partforum_tp_delete_read_records($userid, -1, -1, $forumid);
}


/**
 * Clean old records from the forum_read table.
 * @global object
 * @global object
 * @return void
 */
function partforum_tp_clean_read_records() {
    global $CFG, $DB;

    if (!isset($CFG->forum_oldpostdays)) {
        return;
    }
// Look for records older than the cutoffdate that are still in the forum_read table.
    $cutoffdate = time() - ($CFG->forum_oldpostdays*24*60*60);

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
    return array('view discussion', 'search', 'partforum', 'forums', 'subscribers', 'view forum');
}

/**
 * @return array
 */
function partforum_get_post_actions() {
    return array('add discussion','add post','delete discussion','delete post','move discussion','prune post','update post');
}

/**
 * this function returns all the separate forum ids, given a courseid
 *
 * @global object
 * @global object
 * @param int $courseid
 * @return array
 */
function partforum_get_separate_modules($courseid) {

    global $CFG,$DB;
    $forummodule = $DB->get_record("modules", array("name" => "partforum"));

    $sql = 'SELECT f.id, f.id FROM {partforum} f, {course_modules} cm WHERE
           f.id = cm.instance AND cm.module =? AND cm.visible = 1 AND cm.course = ?
           AND cm.groupmode ='.SEPARATEGROUPS;

    return $DB->get_records_sql($sql, array($forummodule->id, $courseid));

}

/**
 * @global object
 * @global object
 * @global object
 * @param object $forum
 * @param object $cm
 * @return bool
 */
function partforum_check_throttling($forum, $cm=null) {
    global $USER, $CFG, $DB, $OUTPUT;

    if (is_numeric($forum)) {
        $forum = $DB->get_record('partforum',array('id'=>$forum));
    }
    if (!is_object($forum)) {
        return false;  // this is broken.
    }

    if (empty($forum->blockafter)) {
        return true;
    }

    if (empty($forum->blockperiod)) {
        return true;
    }

    if (!$cm) {
        if (!$cm = get_coursemodule_from_instance('partforum', $forum->id, $forum->course)) {
            print_error('invalidcoursemodule');
        }
    }

    $modcontext = get_context_instance(CONTEXT_MODULE, $cm->id);
    if(has_capability('mod/partforum:postwithoutthrottling', $modcontext)) {
        return true;
    }

    // get the number of posts in the last period we care about
    $timenow = time();
    $timeafter = $timenow - $forum->blockperiod;

    $numposts = $DB->count_records_sql('SELECT COUNT(p.id) FROM {partforum_posts} p'
                                      .' JOIN {partforum_discussions} d'
                                      .' ON p.discussion = d.id WHERE d.forum = ?'
                                      .' AND p.userid = ? AND p.created > ?', array($forum->id, $USER->id, $timeafter));

    $a = new stdClass();
    $a->blockafter = $forum->blockafter;
    $a->numposts = $numposts;
    $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);

    if ($forum->blockafter <= $numposts) {
        print_error('forumblockingtoomanyposts', 'error', $CFG->wwwroot.'/mod/partforum/view.php?f='.$forum->id, $a);
    }
    if ($forum->warnafter <= $numposts) {
        echo $OUTPUT->notification(get_string('forumblockingalmosttoomanyposts','partforum',$a));
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

    if ($forums = $DB->get_records_sql($sql, $params)) {
        foreach ($forums as $forum) {
            partforum_grade_item_update($forum, 'reset');
        }
    }
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * This function will remove all posts from the specified forum
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
    if (!empty($data->reset_forum_all)) {
        $removeposts = true;
        $typesstr    = get_string('resetforumsall', 'partforum');
        $types       = array();
    } else if (!empty($data->reset_forum_types)){
        $removeposts = true;
        $typesql     = "";
        $types       = array();
        $forum_types_all = partforum_get_forum_types_all();
        foreach ($data->reset_forum_types as $type) {
            if (!array_key_exists($type, $forum_types_all)) {
                continue;
            }
            $typesql .= " AND f.type=?";
            $types[] = $forum_types_all[$type];
            $params[] = $type;
        }
        $typesstr = get_string('resetforums', 'partforum').': '.implode(', ', $types);
    }
    $alldiscussionssql = "SELECT fd.id
                            FROM {partforum_discussions} fd, {partforum} f
                           WHERE f.course=? AND f.id=fd.forum";

    $allforumssql      = "SELECT f.id
                            FROM {partforum} f
                           WHERE f.course=?";

    $allpostssql       = "SELECT fp.id
                            FROM {partforum_posts} fp, {partforum_discussions} fd, {partforum} f
                           WHERE f.course=? AND f.id=fd.forum AND fd.id=fp.discussion";

    $forumssql = $forums = $rm = null;

    if( $removeposts || !empty($data->reset_forum_ratings) ) {
        $forumssql      = "$allforumssql $typesql";
        $forums = $forums = $DB->get_records_sql($forumssql, $params);
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
        if ($forums) {
            foreach ($forums as $forumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('partforum', $forumid)) {
                    continue;
                }
                $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                $fs->delete_area_files($context->id, 'mod_partforum', 'attachment');
                $fs->delete_area_files($context->id, 'mod_partforum', 'post');

                //remove ratings
                $ratingdeloptions->contextid = $context->id;
                $rm->delete_ratings($ratingdeloptions);
            }
        }

        // first delete all read flags
        $DB->delete_records_select('partforum_read', "forumid IN ($forumssql)", $params);

        // remove tracking prefs
        $DB->delete_records_select('partforum_track_prefs', "forumid IN ($forumssql)", $params);

        // remove posts from queue
        $DB->delete_records_select('partforum_queue', "discussionid IN ($discussionssql)", $params);

        // all posts - initial posts must be kept in single simple discussion forums
        $DB->delete_records_select('partforum_posts', "discussion IN ($discussionssql) AND parent <> 0", $params); // first all children
        $DB->delete_records_select('partforum_posts', "discussion IN ($discussionssql AND f.type <> 'single') AND parent = 0", $params); // now the initial posts for non single simple

        // finally all discussions except single simple forums
        $DB->delete_records_select('partforum_discussions', "forum IN ($forumssql AND f.type <> 'single')", $params);

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

    // remove all ratings in this course's forums
    if (!empty($data->reset_forum_ratings)) {
        if ($forums) {
            foreach ($forums as $forumid=>$unused) {
                if (!$cm = get_coursemodule_from_instance('partforum', $forumid)) {
                    continue;
                }
                $context = get_context_instance(CONTEXT_MODULE, $cm->id);

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
    if (!empty($data->reset_forum_subscriptions)) {
        $DB->delete_records_select('partforum_subscriptions', "forum IN ($allforumssql)", $params);
        $status[] = array('component'=>$componentstr, 'item'=>get_string('resetsubscriptions','partforum'), 'error'=>false);
    }

    // remove all tracking prefs unconditionally - even for users still enrolled in course
    if (!empty($data->reset_forum_track_prefs)) {
        $DB->delete_records_select('partforum_track_prefs', "forumid IN ($allforumssql)", $params);
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
    $mform->addElement('header', 'forumheader', get_string('modulenameplural', 'partforum'));

    $mform->addElement('checkbox', 'reset_forum_all', get_string('resetforumsall','partforum'));

    $mform->addElement('select', 'reset_forum_types', get_string('resetforums', 'partforum'), partforum_get_forum_types_all(), array('multiple' => 'multiple'));
    $mform->setAdvanced('reset_forum_types');
    $mform->disabledIf('reset_forum_types', 'reset_forum_all', 'checked');

    $mform->addElement('checkbox', 'reset_forum_subscriptions', get_string('resetsubscriptions','partforum'));
    $mform->setAdvanced('reset_forum_subscriptions');

    $mform->addElement('checkbox', 'reset_forum_track_prefs', get_string('resettrackprefs','partforum'));
    $mform->setAdvanced('reset_forum_track_prefs');
    $mform->disabledIf('reset_forum_track_prefs', 'reset_forum_all', 'checked');

    $mform->addElement('checkbox', 'reset_forum_ratings', get_string('deleteallratings'));
    $mform->disabledIf('reset_forum_ratings', 'reset_forum_all', 'checked');
}

/**
 * Course reset form defaults.
 * @return array
 */
function partforum_reset_course_form_defaults($course) {
    return array('reset_forum_all'=>1, 'reset_forum_subscriptions'=>0, 'reset_forum_track_prefs'=>0, 'reset_forum_ratings'=>1);
}

/**
 * Converts a forum to use the Roles System
 *
 * @global object
 * @global object
 * @param object $forum        a forum object with the same attributes as a record
 *                        from the forum database table
 * @param int $forummodid   the id of the forum module, from the modules table
 * @param array $teacherroles array of roles that have archetype teacher
 * @param array $studentroles array of roles that have archetype student
 * @param array $guestroles   array of roles that have archetype guest
 * @param int $cmid         the course_module id for this forum instance
 * @return boolean      forum was converted or not
 */
function partforum_convert_to_roles($forum, $forummodid, $teacherroles=array(),
                                $studentroles=array(), $guestroles=array(), $cmid=NULL) {

    global $CFG, $DB, $OUTPUT;

    if (!isset($forum->open) && !isset($forum->assesspublic)) {
        // We assume that this forum has already been converted to use the
        // Roles System. Columns forum.open and forum.assesspublic get dropped
        // once the forum module has been upgraded to use Roles.
        return false;
    }

    if ($forum->type == 'teacher') {

        // Teacher forums should be converted to normal forums that
        // use the Roles System to implement the old behavior.
        // Note:
        //   Seems that teacher forums were never backed up in 1.6 since they
        //   didn't have an entry in the course_modules table.
        require_once($CFG->dirroot.'/course/lib.php');

        if ($DB->count_records('partforum_discussions', array('forum' => $forum->id)) == 0) {
            // Delete empty teacher forums.
            $DB->delete_records('partforum', array('id' => $forum->id));
        } else {
            // Create a course module for the forum and assign it to
            // section 0 in the course.
            $mod = new stdClass();
            $mod->course = $forum->course;
            $mod->module = $forummodid;
            $mod->instance = $forum->id;
            $mod->section = 0;
            $mod->visible = 0;     // Hide the forum
            $mod->visibleold = 0;  // Hide the forum
            $mod->groupmode = 0;

            if (!$cmid = add_course_module($mod)) {
                print_error('cannotcreateinstanceforteacher', 'partforum');
            } else {
                $mod->coursemodule = $cmid;
                if (!$sectionid = add_mod_to_section($mod)) {
                    print_error('cannotaddteacherforumto', 'partforum');
                } else {
                    $DB->set_field('course_modules', 'section', $sectionid, array('id' => $cmid));
                }
            }

            // Change the forum type to general.
            $forum->type = 'general';
            $DB->update_record('partforum', $forum);

            $context = get_context_instance(CONTEXT_MODULE, $cmid);

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
        // Non-teacher forum.

        if (empty($cmid)) {
            // We were not given the course_module id. Try to find it.
            if (!$cm = get_coursemodule_from_instance('partforum', $forum->id)) {
                echo $OUTPUT->notification('Could not get the course module for the forum');
                return false;
            } else {
                $cmid = $cm->id;
            }
        }
        $context = get_context_instance(CONTEXT_MODULE, $cmid);

        // $forum->open defines what students can do:
        //   0 = No discussions, no replies
        //   1 = No discussions, but replies are allowed
        //   2 = Discussions and replies are allowed
        switch ($forum->open) {
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

        // $forum->assessed defines whether forum rating is turned
        // on (1 or 2) and who can rate posts:
        //   1 = Everyone can rate posts
        //   2 = Only teachers can rate posts
        switch ($forum->assessed) {
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

        // $forum->assesspublic defines whether students can see
        // everybody's ratings:
        //   0 = Students can only see their own ratings
        //   1 = Students can see everyone's ratings
        switch ($forum->assesspublic) {
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
 * Returns array of forum layout modes
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
 * Returns array of forum types chooseable on the forum editing form
 *
 * @return array
 */
function partforum_get_forum_types() {
    return array ('general'  => get_string('generalforum', 'partforum'),
    		 	  'participation'   => get_string('partforum', 'partforum'),
                  'eachuser' => get_string('eachuserforum', 'partforum'),
                  'single'   => get_string('singleforum', 'partforum'),
                  'qanda'    => get_string('qandaforum', 'partforum'),
                  'blog'     => get_string('blogforum', 'partforum'));
}

/**
 * Returns array of all forum layout modes
 *
 * @return array
 */
function partforum_get_forum_types_all() {
    return array ('news'     => get_string('namenews','partforum'),
                  'social'   => get_string('namesocial','partforum'),
                  'general'  => get_string('generalforum', 'partforum'),
                  'participation'   => get_string('partforum', 'partforum'),
                  'eachuser' => get_string('eachuserforum', 'partforum'),
                  'single'   => get_string('singleforum', 'partforum'),
                  'qanda'    => get_string('qandaforum', 'partforum'),
                  'blog'     => get_string('blogforum', 'partforum'));
}

/**
 * Returns array of forum open modes
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
 * This function is used to extend the global navigation by add forum nodes if there
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
    forum_get_recent_mod_activity($recentposts, $index, $lastlogin, $course->id, $cm->id);

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
 * @param navigation_node $forumnode The node to add module settings to
 */
function partforum_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $forumnode) {
    global $USER, $PAGE, $CFG, $DB, $OUTPUT;

    $forumobject = $DB->get_record("partforum", array("id" => $PAGE->cm->instance));
    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = get_context_instance(CONTEXT_MODULE, $PAGE->cm->instance);
    }

    // for some actions you need to be enrolled, beiing admin is not enough sometimes here
    $enrolled = is_enrolled($PAGE->cm->context, $USER, '', false);
    $activeenrolled = is_enrolled($PAGE->cm->context, $USER, '', true);

    $canmanage  = has_capability('mod/partforum:managesubscriptions', $PAGE->cm->context);
    $subscriptionmode = partforum_get_forcesubscribed($forumobject);
    $cansubscribe = ($activeenrolled && $subscriptionmode != PARTFORUM_FORCESUBSCRIBE && ($subscriptionmode != PARTFORUM_DISALLOWSUBSCRIBE || $canmanage));

    if ($canmanage) {
        $mode = $forumnode->add(get_string('subscriptionmode', 'partforum'), null, navigation_node::TYPE_CONTAINER);

        $allowchoice = $mode->add(get_string('subscriptionoptional', 'partforum'), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>PARTFORUM_CHOOSESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceforever = $mode->add(get_string("subscriptionforced", "partforum"), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>PARTFORUM_FORCESUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $forceinitially = $mode->add(get_string("subscriptionauto", "partforum"), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>PARTFORUM_INITIALSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);
        $disallowchoice = $mode->add(get_string('subscriptiondisabled', 'partforum'), new moodle_url('/mod/partforum/subscribe.php', array('id'=>$forumobject->id, 'mode'=>PARTFORUM_DISALLOWSUBSCRIBE, 'sesskey'=>sesskey())), navigation_node::TYPE_SETTING);

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
                $notenode = $forumnode->add(get_string('subscriptionoptional', 'partforum'));
                break;
            case PARTFORUM_FORCESUBSCRIBE : // 1
                $notenode = $forumnode->add(get_string('subscriptionforced', 'partforum'));
                break;
            case PARTFORUM_INITIALSUBSCRIBE : // 2
                $notenode = $forumnode->add(get_string('subscriptionauto', 'partforum'));
                break;
            case PARTFORUM_DISALLOWSUBSCRIBE : // 3
                $notenode = $forumnode->add(get_string('subscriptiondisabled', 'partforum'));
                break;
        }
    }

    if ($cansubscribe) {
        if (partforum_is_subscribed($USER->id, $forumobject)) {
            $linktext = get_string('unsubscribe', 'partforum');
        } else {
            $linktext = get_string('subscribe', 'partforum');
        }
        $url = new moodle_url('/mod/partforum/subscribe.php', array('id'=>$forumobject->id, 'sesskey'=>sesskey()));
        $forumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
    }

    if (has_capability('mod/partforum:viewsubscribers', $PAGE->cm->context)){
        $url = new moodle_url('/mod/partforum/subscribers.php', array('id'=>$forumobject->id));
        $forumnode->add(get_string('showsubscribers', 'partforum'), $url, navigation_node::TYPE_SETTING);
    }

    if ($enrolled && partforum_tp_can_track_forums($forumobject)) { // keep tracking info for users with suspended enrolments
        if ($forumobject->trackingtype != PARTFORUM_TRACKING_OPTIONAL) {
            //tracking forced on or off in forum settings so dont provide a link here to change it
            //could add unclickable text like for forced subscription but not sure this justifies adding another menu item
        } else {
            if (partforum_tp_is_tracked($forumobject)) {
                $linktext = get_string('notrackforum', 'partforum');
            } else {
                $linktext = get_string('trackforum', 'partforum');
            }
            $url = new moodle_url('/mod/partforum/settracking.php', array('id'=>$forumobject->id));
            $forumnode->add($linktext, $url, navigation_node::TYPE_SETTING);
        }
    }

    if ($enrolled && !empty($CFG->enablerssfeeds) && !empty($CFG->forum_enablerssfeeds) && $forumobject->rsstype && $forumobject->rssarticles) {

        if (!function_exists('rss_get_url')) {
            require_once("$CFG->libdir/rsslib.php");
        }

        if ($forumobject->rsstype == 1) {
            $string = get_string('rsssubscriberssdiscussions','partforum');
        } else {
            $string = get_string('rsssubscriberssposts','partforum');
        }
        if (!isloggedin()) {
            $userid = 0;
        } else {
            $userid = $USER->id;
        }
        $url = new moodle_url(rss_get_url($PAGE->cm->context->id, $userid, "mod_partforum", $forumobject->id));
        $forumnode->add($string, $url, settings_navigation::TYPE_SETTING, null, null, new pix_icon('i/rss', ''));
    }
}

/**
 * Abstract class used by forum subscriber selection controls
 * @package mod-forum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class partforum_subscriber_selector_base extends user_selector_base {

    /**
     * The id of the forum this selector is being used for
     * @var int
     */
    protected $forumid = null;
    /**
     * The context of the forum this selector is being used for
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
        if (isset($options['forumid'])) {
            $this->forumid = $options['forumid'];
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
        $options['forumid'] = $this->forumid;
        return $options;
    }

}

/**
 * A user selector control for potential subscribers to the selected forum
 * @package mod-forum
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class partforum_potential_subscriber_selector extends partforum_subscriber_selector_base {

    /**
     * If set to true EVERYONE in this course is force subscribed to this forum
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
     * Sets this forum as force subscribed or not
     */
    public function set_force_subscribed($setting=true) {
        $this->forcesubscribed = true;
    }
}

/**
 * User selector control for removing subscribed users
 * @package mod-forum
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
        $params['forumid'] = $this->forumid;

        // only active enrolled or everybody on the frontpage
        list($esql, $eparams) = get_enrolled_sql($this->context, '', $this->currentgroup, true);
        $params = array_merge($params, $eparams);

        $fields = $this->required_fields_sql('u');

        $subscribers = $DB->get_records_sql("SELECT $fields
                                               FROM {user} u
                                               JOIN ($esql) je ON je.id = u.id
                                               JOIN {partforum_subscriptions} s ON s.userid = u.id
                                              WHERE $wherecondition AND s.forum = :forumid
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
        if ($usetracking = partforum_tp_can_track_forums()) {
            $strunreadpostsone = get_string('unreadpostsone', 'partforum');
        }
        $initialised = true;
    }

    if ($usetracking) {
        if ($unread = partforum_tp_count_forum_unread_posts($cm, $cm->get_course())) {
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
    $forum_pagetype = array(
        'mod-forum-*'=>get_string('page-mod-forum-x', 'partforum'),
        'mod-forum-view'=>get_string('page-mod-forum-view', 'partforum'),
        'mod-forum-discuss'=>get_string('page-mod-forum-discuss', 'partforum')
    );
    return $forum_pagetype;
}
