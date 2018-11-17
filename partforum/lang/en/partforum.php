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
 * Strings for component 'partforum', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package   partforum
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addanewdiscussion'] = 'Add a new discussion topic';
$string['addanewgrouppost'] = 'Add a new group post';
$string['addanewquestion'] = 'Add a new question';
$string['addanewtopic'] = 'Add a new topic';
$string['advancedsearch'] = 'Advanced search';
$string['allpartforums'] = 'All forums';
$string['allowdiscussions'] = 'Can a {$a} post to this participation forum?';
$string['allowsallsubscribe'] = 'This participation forum allows everyone to choose whether to subscribe or not';
$string['allowsdiscussions'] = 'This participation forum allows each person to start one discussion topic.';
$string['allsubscribe'] = 'Subscribe to all participation forums';
$string['allunsubscribe'] = 'Unsubscribe from all participation forums';
$string['alreadyfirstpost'] = 'This is already the first post in the discussion';
$string['anyfile'] = 'Any file';
$string['attachment'] = 'Attachment';
$string['attachment_help'] = 'You can optionally attach one or more files to a forum post. If you attach an image, it will be displayed after the message.';
$string['attachmentnopost'] = 'You cannot export attachments without a post id';
$string['attachments'] = 'Attachments';
$string['blockafter'] = 'Post threshold for blocking';
$string['blockafter_help'] = 'This setting specifies the maximum number of posts which a user can post in the given time period. Users with the capability mod/partforum:postwithoutthrottling are exempt from post limits.';
$string['blockperiod'] = 'Time period for blocking';
$string['blockperiod_help'] = 'Students can be blocked from posting more than a given number of posts in a given time period. Users with the capability mod/partforum:postwithoutthrottling are exempt from post limits.';
$string['blockperioddisabled'] = 'Don\'t block';
$string['blogpartforum'] = 'Standard participation forum displayed in a blog-like format';
$string['bynameondate'] = 'by {$a->name} - {$a->date}';
$string['cannotadd'] = 'Could not add the discussion for this participation forum';
$string['cannotadddiscussion'] = 'Adding discussions to this participation forum requires group membership.';
$string['cannotadddiscussionall'] = 'You do not have permission to add a new discussion topic for all participants.';
$string['cannotaddsubscriber'] = 'Could not add subscriber with id {$a} to this participation forum!';
$string['cannotaddteacherpartforumto'] = 'Could not add converted teacher forum instance to section 0 in the course';
$string['cannotcreatediscussion'] = 'Could not create new discussion';
$string['cannotcreateinstanceforteacher'] = 'Could not create new course module instance for the teacher forum';
$string['cannotdeletepartforummodule'] = 'You can not delete the participation forum module.';
$string['cannotdeletepost'] = 'You can\'t delete this post!';
$string['cannoteditposts'] = 'You can\'t edit other people\'s posts!';
$string['cannotfinddiscussion'] = 'Could not find the discussion in this participation forum';
$string['cannotfindfirstpost'] = 'Could not find the first post in this participation forum';
$string['cannotfindorcreatepartforum'] = 'Could not find or create a main news participation forum for the site';
$string['cannotfindparentpost'] = 'Could not find top parent of post {$a}';
$string['cannotmovefromsinglepartforum'] = 'Cannot move discussion from a simple single discussion forum';
$string['cannotmovenotvisible'] = 'Participation forum not visible';
$string['cannotmovetonotexist'] = 'You can\'t move to that participation forum - it doesn\'t exist!';
$string['cannotmovetonotfound'] = 'Target participation forum not found in this course.';
$string['cannotpurgecachedrss'] = 'Could not purge the cached RSS feeds for the source and/or destination forum(s) - check your file permissionsforums';
$string['cannotremovesubscriber'] = 'Could not remove subscriber with id {$a} from this forum!';
$string['cannotreply'] = 'You cannot reply to this post';
$string['cannotsplit'] = 'Discussions from this participation forum cannot be split';
$string['cannotsubscribe'] = 'Sorry, but you must be a group member to subscribe.';
$string['cannottrack'] = 'Could not stop tracking that participation forum';
$string['cannotunsubscribe'] = 'Could not unsubscribe you from that participation forum';
$string['cannotupdatepost'] = 'You can not update this post';
$string['cannotviewpostyet'] = 'You cannot read other students questions in this discussion yet because you haven\'t posted';
$string['cleanreadtime'] = 'Mark old posts as read hour';
$string['completiondiscussions'] = 'Student must create discussions:';
$string['completiondiscussionsgroup'] = 'Require discussions';
$string['completiondiscussionshelp'] = 'requiring discussions to complete';
$string['completionposts'] = 'Student must post discussions or replies:';
$string['completionpostsgroup'] = 'Require posts';
$string['completionpostshelp'] = 'requiring discussions or replies to complete';
$string['completionreplies'] = 'Student must post replies:';
$string['completionrepliesgroup'] = 'Require replies';
$string['completionreplieshelp'] = 'requiring replies to complete';
$string['configcleanreadtime'] = 'The hour of the day to clean old posts from the \'read\' table.';
$string['configdigestmailtime'] = 'People who choose to have emails sent to them in digest form will be emailed the digest daily. This setting controls which time of day the daily mail will be sent (the next cron that runs after this hour will send it).';
$string['configdisplaymode'] = 'The default display mode for discussions if one isn\'t set.';
$string['configenablerssfeeds'] = 'This switch will enable the possibility of RSS feeds for all participation forums.  You will still need to turn feeds on manually in the settings for each participation forum.';
$string['configenabletimedposts'] = 'Set to \'yes\' if you want to allow setting of display periods when posting a new forum discussion (Experimental as not yet fully tested)';
$string['configlongpost'] = 'Any post over this length (in characters not including HTML) is considered long. Posts displayed on the site front page, social format course pages, or user profiles are shortened to a natural break somewhere between the forum_shortpost and forum_longpost values.';
$string['configmanydiscussions'] = 'Maximum number of discussions shown in a forum per page';
$string['configmaxattachments'] = 'Default maximum number of attachments allowed per post.';
$string['configmaxbytes'] = 'Default maximum size for all forum attachments on the site (subject to course limits and other local settings)';
$string['configoldpostdays'] = 'Number of days old any post is considered read.';
$string['configreplytouser'] = 'When a forum post is mailed out, should it contain the user\'s email address so that recipients can reply personally rather than via the forum? Even if set to \'Yes\' users can choose in their profile to keep their email address secret.';
$string['configshortpost'] = 'Any post under this length (in characters not including HTML) is considered short (see below).';
$string['configtrackreadposts'] = 'Set to \'yes\' if you want to track read/unread for each user.';
$string['configusermarksread'] = 'If \'yes\', the user must manually mark a post as read. If \'no\', when the post is viewed it is marked as read.';
$string['confirmsubscribe'] = 'Do you really want to subscribe to participation forum \'{$a}\'?';
$string['confirmunsubscribe'] = 'Do you really want to unsubscribe from participation forum \'{$a}\'?';
$string['couldnotadd'] = 'Could not add your post due to an unknown error';
$string['couldnotdeletereplies'] = 'Sorry, that cannot be deleted as people have already responded to it';
$string['couldnotupdate'] = 'Could not update your post due to an unknown error';
$string['delete'] = 'Delete';
$string['deleteddiscussion'] = 'The discussion topic has been deleted';
$string['deletedpost'] = 'The post has been deleted';
$string['deletedposts'] = 'Those posts have been deleted';
$string['deletesure'] = 'Are you sure you want to delete this post?';
$string['deletesureplural'] = 'Are you sure you want to delete this post and all replies? ({$a} posts)';
$string['digestmailheader'] = 'This is your daily digest of new posts from the {$a->sitename} forums. To change your forum email preferences, go to {$a->userprefs}.';
$string['digestmailprefs'] = 'your user profile';
$string['digestmailsubject'] = '{$a}: forum digest';
$string['digestmailtime'] = 'Hour to send digest emails';
$string['digestsentusers'] = 'Email digests successfully sent to {$a} users.';
$string['disallowsubscribe'] = 'Subscriptions not allowed';
$string['disallowsubscribeteacher'] = 'Subscriptions not allowed (except for teachers)';
$string['discussion'] = 'Discussion';
$string['discussionmoved'] = 'This discussion has been moved to \'{$a}\'.';
$string['discussionmovedpost'] = 'This discussion has been moved to <a href="{$a->discusshref}">here</a> in the forum <a href="{$a->partforumhref}">{$a->partforumname}</a>';
$string['discussionname'] = 'Discussion name';
$string['discussions'] = 'Discussions';
$string['discussionsstartedby'] = 'Discussions started by {$a}';
$string['discussionsstartedbyrecent'] = 'Discussions recently started by {$a}';
$string['discussthistopic'] = 'Discuss this topic';
$string['displayend'] = 'Display end';
$string['displayend_help'] = 'This setting specifies whether a forum post should be hidden after a certain date. Note that administrators can always view forum posts.';
$string['displaymode'] = 'Display mode';
$string['displayperiod'] = 'Display period';
$string['displaystart'] = 'Display start';
$string['displaystart_help'] = 'This setting specifies whether a forum post should be displayed from a certain date. Note that administrators can always view forum posts.';
$string['eachuserpartforum'] = 'Each person posts one discussion';
$string['edit'] = 'Edit';
$string['editedby'] = 'Edited by {$a->name} - original submission {$a->date}';
$string['editing'] = 'Editing';
$string['emptymessage'] = 'Something was wrong with your post. Perhaps you left it blank, or the attachment was too big. Your changes have NOT been saved.';
$string['erroremptymessage'] = 'Post message cannot be empty';
$string['erroremptysubject'] = 'Post subject cannot be empty.';
$string['errorwhiledelete'] = 'An error occurred while deleting record.';
$string['everyonecanchoose'] = 'Everyone can choose to be subscribed';
$string['everyonecannowchoose'] = 'Everyone can now choose to be subscribed';
$string['everyoneisnowsubscribed'] = 'Everyone is now subscribed to this forum';
$string['everyoneissubscribed'] = 'Everyone is subscribed to this forum';
$string['existingsubscribers'] = 'Existing subscribers';
$string['exportdiscussion'] = 'Export whole discussion';
$string['forcessubscribe'] = 'This forum forces everyone to be subscribed';
$string['partforum'] = 'Forum';
$string['partforum:addnews'] = 'Add news';
$string['partforumauthorhidden'] = 'Author (hidden)';
$string['partforumblockingalmosttoomanyposts'] = 'You are approaching the posting threshold. You have posted {$a->numposts} times in the last {$a->blockperiod} and the limit is {$a->blockafter} posts.';
$string['partforumbodyhidden'] = 'This post cannot be viewed by you, probably because you have not posted in the discussion or the maximum editing time hasn\'t passed yet.';
$string['partforum:createattachment'] = 'Create attachments';
$string['partforum:deleteanypost'] = 'Delete any posts (anytime)';
$string['partforum:deleteownpost'] = 'Delete own posts (within deadline)';
$string['partforum:editanypost'] = 'Edit any post';
$string['partforum:exportdiscussion'] = 'Export whole discussion';
$string['partforum:exportownpost'] = 'Export own post';
$string['partforum:exportpost'] = 'Export post';
$string['partforum:initialsubscriptions'] = 'Initial subscription';
$string['partforumintro'] = 'Forum introduction';

$string['partforum:managesubscriptions'] = 'Manage subscriptions';
$string['partforum:movediscussions'] = 'Move discussions';
$string['partforum:postwithoutthrottling'] = 'Exempt from post threshold';
$string['partforumname'] = 'Forum name';
$string['partforumposts'] = 'Forum posts';
$string['partforum:rate'] = 'Rate posts';
$string['partforum:replynews'] = 'Reply to news';
$string['partforum:replypost'] = 'Reply to posts';
$string['partforums'] = 'Forums';
$string['partforum:splitdiscussions'] = 'Split discussions';
$string['partforum:startdiscussion'] = 'Start new discussions';
$string['partforumsubjecthidden'] = 'Subject (hidden)';
$string['partforum:throttlingapplies'] = 'Throttling applies';
$string['partforumtracked'] = 'Unread posts are being tracked';
$string['partforumtrackednot'] = 'Unread posts are not being tracked';
$string['partforumtype'] = 'Forum type';
$string['partforumtype_help'] = 'There are 6 forum types:

* A single simple discussion - A single discussion topic which everyone can reply to.
* Each person posts one discussion - Each student can post exactly one new discussion topic, which everyone can then reply to.
* Participation forum - The participation forum automatically assigns quantitative ratings of participation for each student.
* Q and A forum - Students must first post their perspectives before viewing other students\' posts.
* Standard forum displayed in a blog-like format - An open forum where anyone can start a new discussion at any time, and in which discussion topics are displayed on one page with "Discuss this topic" links.
* Standard forum for general use - An open forum where anyone can start a new discussion at any time.';
$string['partforum:viewallratings'] = 'View all raw ratings given by individuals';
$string['partforum:viewanyrating'] = 'View total ratings that anyone received';
$string['partforum:viewdiscussion'] = 'View discussions';
$string['partforum:viewhiddentimedposts'] = 'View hidden timed posts';
$string['partforum:viewqandawithoutposting'] = 'Always see Q and A posts';
$string['partforum:viewrating'] = 'View the total rating you received';
$string['partforum:viewsubscribers'] = 'View subscribers';
$string['generalpartforum'] = 'Standard forum for general use';
$string['generalpartforums'] = 'General forums';
$string['grouppost'] = 'Group Post';
$string['inpartforum'] = 'in {$a}';
$string['introblog'] = 'The posts in this forum were copied here automatically from blogs of users in this course because those blog entries are no longer available';
$string['intronews'] = 'General news and announcements';
$string['introsocial'] = 'An open forum for chatting about anything you want to';
$string['introteacher'] = 'A forum for teacher-only notes and discussion';
$string['invalidaccess'] = 'This page was not accessed correctly';
$string['invaliddiscussionid'] = 'Discussion ID was incorrect or no longer exists';
$string['invalidforcesubscribe'] = 'Invalid force subscription mode';
$string['invalidpartforumid'] = 'Forum ID was incorrect';
$string['invalidparentpostid'] = 'Parent post ID was incorrect';
$string['invalidpostid'] = 'Invalid post ID - {$a}';
$string['lastpost'] = 'Last post';
$string['learningpartforums'] = 'Learning forums';
$string['longpost'] = 'Long post';
$string['mailnow'] = 'Mail now';
$string['manydiscussions'] = 'Discussions per page';
$string['markalldread'] = 'Mark all posts in this discussion read.';
$string['markallread'] = 'Mark all posts in this forum read.';
$string['markread'] = 'Mark read';
$string['markreadbutton'] = 'Mark<br />read';
$string['markunread'] = 'Mark unread';
$string['markunreadbutton'] = 'Mark<br />unread';
$string['maxattachments'] = 'Maximum number of attachments';
$string['maxattachments_help'] = 'This setting specifies the maximum number of files that can be attached to a forum post.';
$string['maxattachmentsize'] = 'Maximum attachment size';
$string['maxattachmentsize_help'] = 'This setting specifies the largest size of file that can be attached to a forum post.';
$string['maxtimehaspassed'] = 'Sorry, but the maximum time for editing this post ({$a}) has passed!';
$string['message'] = 'Message';
$string['messageprovider:digests'] = 'Subscribed forum digests';
$string['messageprovider:posts'] = 'Subscribed forum posts';
$string['missingsearchterms'] = 'The following search terms occur only in the HTML markup of this message:';
$string['modeflatnewestfirst'] = 'Display replies flat, with newest first';
$string['modeflatoldestfirst'] = 'Display replies flat, with oldest first';
$string['modenested'] = 'Display replies in nested form';
$string['modethreaded'] = 'Display replies in threaded form';
$string['modulename'] = 'Participation Forum';
// update on 20150218 by Murphy
// $string['modulename_help'] = 'The partforum module enables participants to have asynchronous discussions.';
$string['modulename_help'] = 'The Participation Forum module is a customized forum designed to engage students in a collaborative online discussion as a formative learning activity.  It creates a required activity worth up to 100 points, which the students can earn by participating in the discussion.

It has three primary features:
<p> 1. The "Add a new group post" button creates "semi-private" areas for discussions by small groups of students (size 3 to 8 is recommended).</p>
<p> 2. It appends detailed task design instructions  (below the forum topic questions) which describe the types of posts expected:</p>
   <ul>
   <li> Group post</li>
   <li> First post by each student</li>
   <li>  Questions</li>
   <li>  Answers</li>
   <li> Reflective post</li>
   </ul>
<p> 3. It automatically generates a rating of participation based on the level of activity by each student (up to 100 points available) and stores it in the course Gradebook.</p>';

$string['modulename_link'] = 'forum';
$string['modulenameplural'] = 'Participation Forums';
$string['more'] = 'more';
$string['movedmarker'] = '(Moved)';
$string['movethisdiscussionto'] = 'Move this discussion to ...';
$string['mustprovidediscussionorpost'] = 'You must provide either a discussion id or post id to export';
$string['namenews'] = 'News forum';
$string['namenews_help'] = 'The news forum is a special forum for announcements that is automatically created when a course is created. A course can have only one news forum. Only teachers and administrators can post in the news forum. The "Latest news" block will display recent discussions from the news forum.';
$string['namesocial'] = 'Social forum';
$string['nameteacher'] = 'Teacher forum';
$string['newpartforumposts'] = 'New forum posts';
$string['noattachments'] = 'There are no attachments to this post';
$string['nodiscussions'] = 'There are no discussion topics yet in this forum';
$string['nodiscussionsstartedby'] = 'No discussions started by this user';
$string['nogroupposts'] = 'There are no group posts yet in this forum';
$string['noguestpost'] = 'Sorry, guests are not allowed to post.';
$string['noguesttracking'] = 'Sorry, guests are not allowed to set tracking options.';
$string['nomorepostscontaining'] = 'No more posts containing \'{$a}\' were found';
$string['nonews'] = 'No news has been posted yet';
$string['noonecansubscribenow'] = 'Subscriptions are now disallowed';
$string['nopermissiontosubscribe'] = 'You do not have the permission to view forum subscribers';
$string['nopermissiontoview'] = 'You do not have permissions to view this post';
$string['nopostpartforum'] = 'Sorry, you are not allowed to post to this forum';
$string['noposts'] = 'No posts';
$string['nopostscontaining'] = 'No posts containing \'{$a}\' were found';
$string['noquestions'] = 'There are no questions yet in this forum';
$string['nosubscribers'] = 'There are no subscribers yet for this forum';
$string['notexists'] = 'Discussion no longer exists';
$string['nothingnew'] = 'Nothing new for {$a}';
$string['notingroup'] = 'Sorry, but you need to be part of a group to see this forum.';
$string['notinstalled'] = 'The forum module is not installed';
$string['notpartofdiscussion'] = 'This post is not part of a discussion!';
$string['notrackpartforum'] = 'Don\'t track unread posts';
$string['noviewdiscussionspermission'] = 'You do not have the permission to view discussions in this forum';
$string['nowallsubscribed'] = 'All forums in {$a} are subscribed.';
$string['nowallunsubscribed'] = 'All forums in {$a} are not subscribed.';
$string['nownotsubscribed'] = '{$a->name} will NOT receive copies of \'{$a->partforum}\' by email.';
$string['nownottracking'] = '{$a->name} is no longer tracking \'{$a->partforum}\'.';
$string['nowsubscribed'] = '{$a->name} will receive copies of \'{$a->partforum}\' by email.';
$string['nowtracking'] = '{$a->name} is now tracking \'{$a->partforum}\'.';
$string['numposts'] = '{$a} posts';
$string['olderdiscussions'] = 'Older discussions';
$string['oldertopics'] = 'Older topics';
$string['oldpostdays'] = 'Read after days';
$string['openmode0'] = 'No discussions, no replies';
$string['openmode1'] = 'No discussions, but replies are allowed';
$string['openmode2'] = 'Discussions and replies are allowed';
$string['overviewnumpostssince'] = 'posts since last login';
$string['overviewnumunread'] = 'total unread';
$string['page-mod-partforum-x'] = 'Any forum module page';
$string['page-mod-partforum-view'] = 'Forum module main page';
$string['page-mod-partforum-discuss'] = 'Forum module discussion thread page';
$string['parent'] = 'Show parent';
$string['parentofthispost'] = 'Parent of this post';
$string['partforum'] = 'Participation forum';
$string['pluginadministration'] = 'Participation Forum administration';
$string['pluginname'] = 'Participation Forum';
$string['postadded'] = '<p>Your post was successfully added.</p> <p>You have {$a} to edit it if you want to make any changes.</p>';
$string['postaddedsuccess'] = 'Your post was successfully added.';
$string['postaddedtimeleft'] = 'You have {$a} to edit it if you want to make any changes.';
$string['postincontext'] = 'See this post in context';
$string['postmailinfo'] = 'This is a copy of a message posted on the {$a} website.

To reply click on this link:';
$string['postmailnow'] = '<p>This post will be mailed out immediately to all forum subscribers.</p>';
$string['postrating1'] = 'Mostly separate knowing';
$string['postrating2'] = 'Separate and connected';
$string['postrating3'] = 'Mostly connected knowing';
$string['posts'] = 'Posts';
$string['posttopartforum'] = 'Create my group post';
$string['posttoforumreply']='Post to the forum';
$string['postupdated'] = 'Your post was updated';
$string['potentialsubscribers'] = 'Potential subscribers';
$string['processingdigest'] = 'Processing email digest for user {$a}';
$string['processingpost'] = 'Processing post {$a}';
$string['prune'] = 'Split';
$string['prunedpost'] = 'A new discussion has been created from that post';
$string['pruneheading'] = 'Split the discussion and move this post to a new discussion';
$string['qandapartforum'] = 'Q and A forum';
$string['qandanotify'] = 'This is a question and answer forum. In order to see other responses to these questions, you must first post your answer';
$string['re'] = 'Re:';
$string['readtherest'] = 'Read the rest of this topic';
$string['replies'] = 'Replies';
$string['repliesmany'] = '{$a} replies so far';
$string['repliesone'] = '{$a} reply so far';
$string['reply'] = 'Reply';
$string['replypartforum'] = 'Reply to forum';
$string['replytouser'] = 'Use email address in reply';
$string['replytype'] = 'Reply type';
$string['replytype_help'] = 'A substantive contribution will be given credit while a social comment will not.';
$string['resetpartforums'] = 'Delete posts from';
$string['resetpartforumsall'] = 'Delete all posts';
$string['resetsubscriptions'] = 'Delete all forum subscriptions';
$string['resettrackprefs'] = 'Delete all forum tracking preferences';
$string['rsssubscriberssdiscussions'] = 'RSS feed of discussions';
$string['rsssubscriberssposts'] = 'RSS feed of posts';
$string['rssarticles'] = 'Number of RSS recent articles';
$string['rssarticles_help'] = 'This setting specifies the number of articles (either discussions or posts) to include in the RSS feed. Between 5 and 20 generally acceptable.';
$string['rsstype'] = 'RSS feed for this activity';
$string['rsstype_help'] = 'To enable the RSS feed for this activity, select either discussions or posts to be included in the feed.';
$string['search'] = 'Search';
$string['searchdatefrom'] = 'Posts must be newer than this';
$string['searchdateto'] = 'Posts must be older than this';
$string['searchpartforumintro'] = 'Please enter search terms into one or more of the following fields:';
$string['searchpartforums'] = 'Search forums';
$string['searchfullwords'] = 'These words should appear as whole words';
$string['searchnotwords'] = 'These words should NOT be included';
$string['searcholderposts'] = 'Search older posts...';
$string['searchphrase'] = 'This exact phrase must appear in the post';
$string['searchresults'] = 'Search results';
$string['searchsubject'] = 'These words should be in the subject';
$string['searchuser'] = 'This name should match the author';
$string['searchuserid'] = 'The Moodle ID of the author';
$string['searchwhichpartforums'] = 'Choose which forums to search';
$string['searchwords'] = 'These words can appear anywhere in the post';
$string['seeallposts'] = 'See all posts made by this user';
$string['shortpost'] = 'Short post';
$string['showsubscribers'] = 'Show/edit current subscribers';
$string['singlepartforum'] = 'A single simple discussion';
$string['smallmessage'] = '{$a->user} posted in {$a->partforumname}';
$string['socialcomment'] = 'Social comment';
$string['startedby'] = 'Started by';
$string['subject'] = 'Subject';
$string['subject_default_firstpost'] = 'First Post';
$string['subject_default_qa'] = 'Q & A';
$string['subscribe'] = 'Subscribe to this forum';
$string['subscribeall'] = 'Subscribe everyone to this forum';
$string['subscribeenrolledonly'] = 'Sorry, only enrolled users are allowed to subscribe to receive forum postings by email.';
$string['subscribed'] = 'Subscribed';
$string['subscribenone'] = 'Unsubscribe everyone from this forum';
$string['subscribers'] = 'Subscribers';
$string['subscribersto'] = 'Subscribers to \'{$a}\'';
$string['subscribestart'] = 'Send me email copies of posts to this forum';
$string['subscribestop'] = 'I don\'t want email copies of posts to this forum';
$string['subscription'] = 'Subscription';
$string['subscription_help'] = 'If you are subscribed to a forum it means you will receive email copies of forum posts. Usually you can choose whether you wish to be subscribed, though sometimes subscription is forced so that everyone receives email copies of forum posts.';
$string['subscriptionmode'] = 'Subscription mode';
$string['subscriptionmode_help'] = 'When a participant is subscribed to a forum it means they will receive email copies of forum posts.

There are 4 subscription mode options:

* Optional subscription - Participants can choose whether to be subscribed
* Forced subscription - Everyone is subscribed and cannot unsubscribe
* Auto subscription - Everyone is subscribed initially but can choose to unsubscribe at any time
* Subscription disabled - Subscriptions are not allowed';
$string['subscriptionoptional'] = 'Optional subscription';
$string['subscriptionforced'] = 'Forced subscription';
$string['subscriptionauto'] = 'Auto subscription';
$string['subscriptiondisabled'] = 'Subscription disabled';
$string['subscriptions'] = 'Subscriptions';
$string['substantivecontribution'] = 'Substantive Contribution';
$string['thispartforumisthrottled'] = 'This forum has a limit to the number of forum postings you can make in a given time period - this is currently set at {$a->blockafter} posting(s) in {$a->blockperiod}';
$string['timedposts'] = 'Timed posts';
$string['timestartenderror'] = 'Display end date cannot be earlier than the start date';
$string['trackpartforum'] = 'Track unread posts';
$string['tracking'] = 'Track';
$string['trackingoff'] = 'Off';
$string['trackingon'] = 'On';
$string['trackingoptional'] = 'Optional';
$string['trackingtype'] = 'Read tracking for this forum?';
$string['trackingtype_help'] = 'If enabled, participants can track read and unread messages in the forum and in discussions.

There are three options:

* Optional - Participants can choose whether to turn tracking on or off
* On - Tracking is always on
* Off - Tracking is always off';
$string['unread'] = 'Unread';
$string['unreadposts'] = 'Unread posts';
$string['unreadpostsnumber'] = '{$a} unread posts';
$string['unreadpostsone'] = '1 unread post';
$string['unsubscribe'] = 'Unsubscribe from this forum';
$string['unsubscribeall'] = 'Unsubscribe from all forums';
$string['unsubscribeallconfirm'] = 'You are subscribed to {$a} forums now. Do you really want to unsubscribe from all forums and disable forum auto-subscribe?';
$string['unsubscribealldone'] = 'All your forum subscriptions were removed, you might still receive notifications from forums with forced subscription. If you do not want to receive any emails from this server please go to your profile and disable email address there.';
$string['unsubscribeallempty'] = 'Sorry, you are not subscribed to any forums. If you do not want to receive any emails from this server please go to your profile and disable email address there.';
$string['unsubscribed'] = 'Unsubscribed';
$string['unsubscribeshort'] = 'Unsubscribe';
$string['usermarksread'] = 'Manual message read marking';
$string['viewalldiscussions'] = 'View all discussions';
$string['warnafter'] = 'Post threshold for warning';
$string['warnafter_help'] = 'Students can be warned as they approach the maximum number of posts allowed in a given period. This setting specifies after how many posts they are warned. Users with the capability mod/partforum:postwithoutthrottling are exempt from post limits.';
$string['yournewgrouppost'] = 'Your new group post <i>(this could just list group information, such as a list of names)</i>';
$string['yournewquestion'] = 'Your new question';
$string['yournewtopic'] = 'Your new discussion topic';
$string['yourreply'] = 'Your reply';
$string['enablepopup']='Enable popup to show participation instructions';
$string['popupstr']='Default it set to \'Yes\', so participation instruction will be open up in popup window. If set to \'No\' instructions will be displayed as a normal content.';
$string['popup_heading']='Participation Forum methodology';
$string['partforum_instructions_link']='
<p class=partforum_instrction_link{$a}><span class=partforum_instrction_link>Click here to see the instructions for the Participation Forum</span></p>';

$string['partforumintro_default_partforum'] = '
	<p><b>This discussion activity is automatically rated based on participation, using the <a href="http://participationforum.org" target="_blank">Participation Forum methodology</a></b></p>
	<p><b>Contributions to this discussion must be posted by <em><span style="color: #990000;">{$a}</span></em> to increase your rating.</b></p>
	<ol>
	<li>A member from each group should click the "Add a new group post" button which creates a post restating the discussion topic questions, and enter the Group name in the Subject line, and some text in the Message area (such as group member names, etc). <i>(this post gets no rating)</i></li>
	<li>Each member of the group should Reply to their Group Post with their First Post: notes, or a summary of their initial thoughts about the discussion topic questions. <i>(this post will automatically receive a rating of 6 out of 10 points possible)</i></li>
	<li>Read the first posts of your group members, and post a Question to them by Replying.<i> (your rating will increase to an 8)</i></li>
	<li>Read the questions that have been posted to your group. Do some research, and construct your Answer to a question by Replying. <i>(your rating will increase to 8.67)</i></li>
	<li>A day or two before the Forum due date, Reply to create a Reflective post. Did exposure to others\' thoughts change your viewpoint? <i>(your rating will increase to 9)</i></li>
	</ol>
	<i>Each student should put up a minimum of 4 posts. The more Q &amp; A you post, the higher your participation rating, but be SURE to keep the quality up with thoughtful posts! Note: use Reply Type "Social Comment" to post non-substantive social comments, which do not increase your participation rating (but are often appreciated). Include pictures if you feel they illustrate the concept, and include links or attach files to support your position.</i>
	<br/>
	<br/>
	<p align="center"><span style="font-size: x-small;"><em>The Participation Forum was designed by <a href="http://brant.knutzen.se" target="_blank">Brant Knutzen</a> and the plugin developed by <a href="http://tlextrait.com" target="_blank">Thomas Lextrait</a>, and is released under the  the <a href="http://creativecommons.org/licenses/by-sa/3.0/deed.en_US" target="_blank">Creative Commons BY-SA license</a></em>.</span></p>
';

$string['partforum_instructions']='
     <p>Instructions for the Participation Forum discussion activity:</p>
   	<ol>
	<li>A member from each group should click the "Add a new group post" button which creates a post restating the discussion topic questions, and enter the Group name in the Subject line, and some text in the Message area (such as group member names, etc). <i>(this post gets no rating)</i></li>
	<li>Each member of the group should Reply to their Group Post with their First Post: notes, or a summary of their initial thoughts about the discussion topic questions. <i>(this post will automatically receive a rating of 60%)</i></li>
	<li>Read the first posts of your group members, and post a Question to them by Replying.<i> (your rating will increase to 80%)</i></li>
	<li>Read the questions that have been posted to your group. Do some research, and construct your Answer to a question by Replying. <i>(your rating will increase to 86.7%)</i></li>
	<li>A day or two before the Forum due date, Reply to create a Reflective post. Did exposure to others\' thoughts change your viewpoint? <i>(your rating will increase to 90%)</i></li>
	</ol>
	<i>Each student should put up a minimum of 4 posts. The more Q &amp; A you post, the <span class=partform_image_link onClick=show_rating_map()  >higher your participation rating</span>, but be SURE to keep the quality up with thoughtful posts! Note: use Reply Type "Social Comment" to post non-substantive social comments, which do not increase your participation rating (but are often appreciated). Include pictures if you feel they illustrate the concept, and include links or attach files to support your position.</i>
	<br/>
	<br/>
	<p align="center"><span style="font-size: x-small;"><em>The Participation Forum was designed by <a href="http://brant.knutzen.se" target="_blank">Brant Knutzen</a> and the plugin developed by <a href="http://tlextrait.com" target="_blank">Thomas Lextrait</a>, and is released under the  the <a href="http://creativecommons.org/licenses/by-sa/3.0/deed.en_US" target="_blank">Creative Commons BY-SA license</a></em>.</span></p>';
    
$string['partforum_instructions_baselines']='<p>----------------------------------------------------------------------------------------------------------------</p>
    <p><b>This discussion activity is automatically rated based on participation, using the <a href="http://participationforum.org" target="_blank">Participation Forum methodology</a></b></p>
	<p><b>Contributions to this discussion must be posted by <em><span style="color: #990000;">{$a}</span></em> to increase your rating.</b></p>';
    
$string['partforum_baselineswithoutdates']='<p>----------------------------------------------------------------------------------------------------------------</p>
    <p><b>This discussion activity is automatically rated based on participation, using the <a href="http://participationforum.org" target="_blank">Participation Forum methodology</a></b></p>
	<p><b>Contributions to this discussion will always increase your rating.</b></p>';
    
$string['graph_heading']='Graph relating participation to grade';
$string['partforum_baselines_withoutlines']='<p><b>This discussion activity is automatically rated based on participation, using the <a href="http://participationforum.org" target="_blank">Participation Forum methodology</a></b></p>
	<p><b>Contributions to this discussion must be posted by <em><span style="color: #990000;">{$a}</span></em> to increase your rating.</b></p>';
$string['add_partforum_instructions']='Add instructions for Participation forum';
$string['hidethisspecificpost_reply']='Hide this post replies';
$string['enabletoggleforallposts']='Enable Toggle(hide/show) functionality for all posts';
$string['enablehidelink']='Enable the link to hide all specific replies';
$string['configenablehidelink']='Default it set to \'NO\', So \'Hide this post replies\' link will not appeared, If set to \'YES\' link will be appear in the actions links line.';
$string['configenabletoggleforallposts']='Default it set to NO, so toggle functionality will not be enabled. If set to \'YES\' toggle interface will be appeared for all posts.';
    

