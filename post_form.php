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
 * @copyright Jamie Pratt <me@jamiep.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/formslib.php');

class mod_partforum_post_form extends moodleform {

    function definition() {

        global $CFG, $DB, $USER;
        $mform    =& $this->_form;

        $course        = $this->_customdata['course'];
        $cm            = $this->_customdata['cm'];
        $coursecontext = $this->_customdata['coursecontext'];
        $modcontext    = $this->_customdata['modcontext'];
        $forum         = $this->_customdata['forum'];
        $post          = $this->_customdata['post'];
        // if $forum->maxbytes == '0' means we should use $course->maxbytes
        if ($forum->maxbytes == '0') {
            $forum->maxbytes = $course->maxbytes;
        }
        // TODO: add max files and max size support
        $editoroptions = array('maxfiles' => EDITOR_UNLIMITED_FILES, 'trusttext'=>true, 'context'=>$modcontext);

        $mform->addElement('header', 'general', '');//fill in the data depending on page params later using set_data
        
        $discussion = $post->discussion;
		$userid = $post->userid;
        $count = partforum_count_user_replies($forum->id, $userid);
			
		// Is this the first post?
		switch($count){
			case 0:
				$first_post = true;
				break;
			case 1:
			default:
				$first_post = false;
				break;
		}       
        
        /*
        * Participation Forum - Default discussion subject
        * !!! currently disabled awaiting decision for future use
        if($forum->type == 'participation' && isset($post->parent) && $post->parent > 0){
        
        	$mform->addElement('text', 'subject', get_string('subject', 'partforum'), 'size="48" readonly="readonly"');
			
			if($first_post){
				$default_subject = get_string("subject_default_firstpost","partforum");
			}else{
				$default_subject = get_string("subject_default_qa","partforum");
			}
			
        	$mform->addElement('html', '<script>
        	var s = document.getElementById("id_subject");
        	s.value="'.$default_subject.'";
        	</script>');
			
        }else{*/
        	$mform->addElement('text', 'subject', get_string('subject', 'partforum'), 'size="48"');
        //}
        
        // Clear the subject field and focus
        $count_posts = partforum_count_user_posts($forum->id, $userid);
/*echo '<p>count:<b>';
echo print_r($count_posts);
echo '</b></p>';        
*/      
        
        if ($count_posts && $count_posts->postcount == 0) {
            $mform->addElement('html', '<script>
                    var s = document.getElementById("id_subject");
                    s.value="";
                    s.focus();
                    </script>');
        }
        
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        
		/*
		* Message editor
		*/
        $mform->addElement('editor', 'message', get_string('message', 'partforum'), null, $editoroptions);
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', get_string('required'), 'required', null, 'client');
		
		/*
		* Participation Forum - Reply type
		* (Doesn't appear if this is the first post in the discussion)
		*/
		if($forum->type == 'participation' && isset($post->parent) && $post->parent > 0){
			$options = array();
            $options[0] = get_string('substantivecontribution', 'partforum');
            
            // First post cannot be a social comment
            if(!isset($first_post) || !$first_post){
	            $options[1] = get_string('socialcomment', 'partforum');
            }

            $mform->addElement('select', 'replytype', get_string('replytype', 'partforum'), $options);
            
            $mform->addHelpButton('replytype', 'replytype', 'partforum');
		}
		
		
		/*
		* Subscription settings
		*/
        if (isset($forum->id) && partforum_is_forcesubscribed($forum)) {

            $mform->addElement('static', 'subscribemessage', get_string('subscription', 'partforum'), get_string('everyoneissubscribed', 'partforum'));
            $mform->addElement('hidden', 'subscribe');
            $mform->setType('subscribe', PARAM_INT);
            $mform->addHelpButton('subscribemessage', 'subscription', 'partforum');

        } else if (isset($forum->forcesubscribe)&& $forum->forcesubscribe != PARTFORUM_DISALLOWSUBSCRIBE ||
                   has_capability('moodle/course:manageactivities', $coursecontext)) {

            $options = array();
            $options[0] = get_string('subscribestop', 'partforum');
            $options[1] = get_string('subscribestart', 'partforum');

            $mform->addElement('select', 'subscribe', get_string('subscription', 'partforum'), $options);
            $mform->addHelpButton('subscribe', 'subscription', 'partforum');
        } else if ($forum->forcesubscribe == PARTFORUM_DISALLOWSUBSCRIBE) {
            $mform->addElement('static', 'subscribemessage', get_string('subscription', 'partforum'), get_string('disallowsubscribe', 'partforum'));
            $mform->addElement('hidden', 'subscribe');
            $mform->setType('subscribe', PARAM_INT);
            $mform->addHelpButton('subscribemessage', 'subscription', 'partforum');
        }

        if (!empty($forum->maxattachments) && $forum->maxbytes != 1 && has_capability('mod/partforum:createattachment', $modcontext))  {  //  1 = No attachments at all
            $mform->addElement('filemanager', 'attachments', get_string('attachment', 'partforum'), null,
                array('subdirs'=>0,
                      'maxbytes'=>$forum->maxbytes,
                      'maxfiles'=>$forum->maxattachments,
                      'accepted_types'=>'*',
                      'return_types'=>FILE_INTERNAL));
            $mform->addHelpButton('attachments', 'attachment', 'partforum');
        }

        if (empty($post->id) && has_capability('moodle/course:manageactivities', $coursecontext)) { // hack alert
            $mform->addElement('checkbox', 'mailnow', get_string('mailnow', 'partforum'));
        }

        if (!empty($CFG->forum_enabletimedposts) && !$post->parent && has_capability('mod/partforum:viewhiddentimedposts', $coursecontext)) { // hack alert
            $mform->addElement('header', '', get_string('displayperiod', 'partforum'));

            $mform->addElement('date_selector', 'timestart', get_string('displaystart', 'partforum'), array('optional'=>true));
            $mform->addHelpButton('timestart', 'displaystart', 'partforum');

            $mform->addElement('date_selector', 'timeend', get_string('displayend', 'partforum'), array('optional'=>true));
            $mform->addHelpButton('timeend', 'displayend', 'partforum');
        } else {
            $mform->addElement('hidden', 'timestart');
            $mform->setType('timestart', PARAM_INT);
            $mform->addElement('hidden', 'timeend');
            $mform->setType('timeend', PARAM_INT);
            $mform->setConstants(array('timestart'=> 0, 'timeend'=>0));
        }

        if (groups_get_activity_groupmode($cm, $course)) { // hack alert
            if (empty($post->groupid)) {
                $groupname = get_string('allparticipants');
            } else {
                $group = groups_get_group($post->groupid);
                $groupname = format_string($group->name);
            }
            $mform->addElement('static', 'groupinfo', get_string('group'), $groupname);
        }

        //-------------------------------------------------------------------------------
        // buttons
        if (isset($post->edit)) { // hack alert
            $submit_string = get_string('savechanges');
        } else {
            $submit_string = get_string('posttoforum', 'partforum');
        }
        $this->add_action_buttons(false, $submit_string);

        $mform->addElement('hidden', 'course');
        $mform->setType('course', PARAM_INT);

        $mform->addElement('hidden', 'forum');
        $mform->setType('forum', PARAM_INT);

        $mform->addElement('hidden', 'discussion');
        $mform->setType('discussion', PARAM_INT);

        $mform->addElement('hidden', 'parent');
        $mform->setType('parent', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('hidden', 'groupid');
        $mform->setType('groupid', PARAM_INT);

        $mform->addElement('hidden', 'edit');
        $mform->setType('edit', PARAM_INT);

        $mform->addElement('hidden', 'reply');
        $mform->setType('reply', PARAM_INT);
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['timeend']!=0) && ($data['timestart']!=0) && $data['timeend'] <= $data['timestart']) {
            $errors['timeend'] = get_string('timestartenderror', 'partforum');
        }
        if (empty($data['message']['text'])) {
            $errors['message'] = get_string('erroremptymessage', 'partforum');
        }
        if (empty($data['subject'])) {
            $errors['subject'] = get_string('erroremptysubject', 'partforum');
        }
        return $errors;
    }
}

