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
* This file adds support to rss feeds generation
*
* @package mod-partforum
* @copyright 2001 Eloy Lafuente (stronk7) http://contiento.com
* @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

/**
 * Returns the path to the cached rss feed contents. Creates/updates the cache if necessary.
 * @global object $CFG
 * @global object $DB
 * @param object $context the context
 * @param int $partforumid the ID of the partforum
 * @param array $args the arguments received in the url
 * @return string the full path to the cached RSS feed directory. Null if there is a problem.
 */
function partforum_rss_get_feed($context, $args) {
    global $CFG, $DB;

    $status = true;

    //are RSS feeds enabled?
    if (empty($CFG->partforum_enablerssfeeds)) {
        debugging('DISABLED (module configuration)');
        return null;
    }

    $partforumid  = clean_param($args[3], PARAM_INT);
    $cm = get_coursemodule_from_instance('partforum', $partforumid, 0, false, MUST_EXIST);
    if ($cm) {
        $modcontext = context_module::instance($cm->id);

        //context id from db should match the submitted one
        if ($context->id != $modcontext->id || !has_capability('mod/partforum:viewdiscussion', $modcontext)) {
            return null;
        }
    }

    $partforum = $DB->get_record('partforum', array('id' => $partforumid), '*', MUST_EXIST);
    if (!rss_enabled_for_mod('partforum', $partforum)) {
        return null;
    }

    //the sql that will retreive the data for the feed and be hashed to get the cache filename
    $sql = partforum_rss_get_sql($partforum, $cm);

    //hash the sql to get the cache file name
    $filename = rss_get_file_name($partforum, $sql);
    $cachedfilepath = rss_get_file_full_name('mod_partforum', $filename);

    //Is the cache out of date?
    $cachedfilelastmodified = 0;
    if (file_exists($cachedfilepath)) {
        $cachedfilelastmodified = filemtime($cachedfilepath);
    }
    //if the cache is more than 60 seconds old and there's new stuff
    $dontrecheckcutoff = time()-60;
    if ( $dontrecheckcutoff > $cachedfilelastmodified && partforum_rss_newstuff($partforum, $cm, $cachedfilelastmodified)) {
        //need to regenerate the cached version
        $result = partforum_rss_feed_contents($partforum, $sql);
        if (!empty($result)) {
            $status = rss_save_file('mod_partforum',$filename,$result);
        }
    }

    //return the path to the cached version
    return $cachedfilepath;
}

/**
 * Given a partforum object, deletes all cached RSS files associated with it.
 *
 * @param object $partforum
 * @return void
 */
function partforum_rss_delete_file($partforum) {
    rss_delete_file('mod_partforum', $partforum);
}

///////////////////////////////////////////////////////
//Utility functions

/**
 * If there is new stuff in the partforum since $time this returns true
 * Otherwise it returns false.
 *
 * @param object $partforum the partforum object
 * @param object $cm
 * @param int $time timestamp
 * @return bool
 */
function partforum_rss_newstuff($partforum, $cm, $time) {
    global $DB;

    $sql = partforum_rss_get_sql($partforum, $cm, $time);

    $recs = $DB->get_records_sql($sql, null, 0, 1);//limit of 1. If we get even 1 back we have new stuff
    return ($recs && !empty($recs));
}

function partforum_rss_get_sql($partforum, $cm, $time=0) {
    $sql = null;

    if (!empty($partforum->rsstype)) {
        if ($partforum->rsstype == 1) {    //Discussion RSS
            $sql = partforum_rss_feed_discussions_sql($partforum, $cm, $time);
        } else {                //Post RSS
            $sql = partforum_rss_feed_posts_sql($partforum, $cm, $time);
        }
    }

    return $sql;
}

function partforum_rss_feed_discussions_sql($partforum, $cm, $newsince=0) {
    global $CFG, $DB, $USER;

    $timelimit = '';

    $modcontext = null;

    $now = round(time(), -2);
    $params = array($cm->instance);

    $modcontext = context_module::instance($cm->id);

    if (!empty($CFG->partforum_enabletimedposts)) { /// Users must fulfill timed posts
        if (!has_capability('mod/partforum:viewhiddentimedposts', $modcontext)) {
            $timelimit = " AND ((d.timestart <= :now1 AND (d.timeend = 0 OR d.timeend > :now2))";
            $params['now1'] = $now;
            $params['now2'] = $now;
            if (isloggedin()) {
                $timelimit .= " OR d.userid = :userid";
                $params['userid'] = $USER->id;
            }
            $timelimit .= ")";
        }
    }

    //do we only want new posts?
    if ($newsince) {
        $newsince = " AND p.modified > '$newsince'";
    } else {
        $newsince = '';
    }

    //get group enforcing SQL
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);
    $groupselect = partforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    if ($groupmode && $currentgroup) {
        $params['groupid'] = $currentgroup;
    }

    $partforumsort = "d.timemodified DESC";
    $postdata = "p.id, p.subject, p.created as postcreated, p.modified, p.discussion, p.userid, p.message as postmessage, p.messageformat AS postformat, p.messagetrust AS posttrust";

    $sql = "SELECT $postdata, d.id as discussionid, d.name as discussionname, d.timemodified, d.usermodified, d.groupid, d.timestart, d.timeend,
                   u.firstname as userfirstname, u.lastname as userlastname, u.email, u.picture, u.imagealt
              FROM {partforum_discussions} d
                   JOIN {partforum_posts} p ON p.discussion = d.id
                   JOIN {user} u ON p.userid = u.id
             WHERE d.partforum = {$partforum->id} AND p.parent = 0
                   $timelimit $groupselect $newsince
          ORDER BY $partforumsort";
    return $sql;
}

function partforum_rss_feed_posts_sql($partforum, $cm, $newsince=0) {
    $modcontext = context_module::instance($cm->id);

    //get group enforcement SQL
    $groupmode    = groups_get_activity_groupmode($cm);
    $currentgroup = groups_get_activity_group($cm);

    $groupselect = partforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext);

    if ($groupmode && $currentgroup) {
        $params['groupid'] = $currentgroup;
    }

    //do we only want new posts?
    if ($newsince) {
        $newsince = " AND p.modified > '$newsince'";
    } else {
        $newsince = '';
    }

    $sql = "SELECT p.id AS postid,
                 d.id AS discussionid,
                 d.name AS discussionname,
                 u.id AS userid,
                 u.firstname AS userfirstname,
                 u.lastname AS userlastname,
                 p.subject AS postsubject,
                 p.message AS postmessage,
                 p.created AS postcreated,
                 p.messageformat AS postformat,
                 p.messagetrust AS posttrust
            FROM {partforum_discussions} d,
               {partforum_posts} p,
               {user} u
            WHERE d.partforum = {$partforum->id} AND
                p.discussion = d.id AND
                u.id = p.userid $newsince
                $groupselect
            ORDER BY p.created desc";

    return $sql;
}

function partforum_rss_get_group_sql($cm, $groupmode, $currentgroup, $modcontext=null) {
    $groupselect = '';

    if ($groupmode) {
        if ($groupmode == VISIBLEGROUPS or has_capability('moodle/site:accessallgroups', $modcontext)) {
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            }
        } else {
            //seprate groups without access all
            if ($currentgroup) {
                $groupselect = "AND (d.groupid = :groupid OR d.groupid = -1)";
                $params['groupid'] = $currentgroup;
            } else {
                $groupselect = "AND d.groupid = -1";
            }
        }
    }

    return $groupselect;
}




/**
 * This function return the XML rss contents about the partforum
 * It returns false if something is wrong
 *
 * @param object $partforum
 * @param bool
 */
function partforum_rss_feed_contents($partforum, $sql) {
    global $CFG, $DB;

    $status = true;

    $params = array();
    //$params['partforumid'] = $partforum->id;
    $recs = $DB->get_recordset_sql($sql, $params, 0, $partforum->rssarticles);

    //set a flag. Are we displaying discussions or posts?
    $isdiscussion = true;
    if (!empty($partforum->rsstype) && $partforum->rsstype!=1) {
        $isdiscussion = false;
    }

    $formatoptions = new stdClass();
    $items = array();
    foreach ($recs as $rec) {
            $item = new stdClass();
            $user = new stdClass();
            if ($isdiscussion && !empty($rec->discussionname)) {
                $item->title = format_string($rec->discussionname);
            } else if (!empty($rec->postsubject)) {
                $item->title = format_string($rec->postsubject);
            } else {
                //we should have an item title by now but if we dont somehow then substitute something somewhat meaningful
                $item->title = format_string($partforum->name.' '.userdate($rec->postcreated,get_string('strftimedatetimeshort', 'langconfig')));
            }
            $user->firstname = $rec->userfirstname;
            $user->lastname = $rec->userlastname;
            $item->author = fullname($user);
            $item->pubdate = $rec->postcreated;
            if ($isdiscussion) {
                $item->link = $CFG->wwwroot."/mod/partforum/discuss.php?d=".$rec->discussionid;
            } else {
                $item->link = $CFG->wwwroot."/mod/partforum/discuss.php?d=".$rec->discussionid."&parent=".$rec->postid;
            }

            $formatoptions->trusted = $rec->posttrust;
            $item->description = format_text($rec->postmessage,$rec->postformat,$formatoptions,$partforum->course);

            //TODO: implement post attachment handling
            /*if (!$isdiscussion) {
                $post_file_area_name = str_replace('//', '/', "$partforum->course/$CFG->moddata/partforum/$partforum->id/$rec->postid");
                $post_files = get_directory_list("$CFG->dataroot/$post_file_area_name");

                if (!empty($post_files)) {
                    $item->attachments = array();
                }
            }*/

            $items[] = $item;
        }
    $recs->close();


    if (!empty($items)) {
        //First the RSS header
        $header = rss_standard_header(strip_tags(format_string($partforum->name,true)),
                                      $CFG->wwwroot."/mod/partforum/view.php?f=".$partforum->id,
                                      format_string($partforum->intro,true)); // TODO: fix format
        //Now all the rss items
        if (!empty($header)) {
            $articles = rss_add_items($items);
        }
        //Now the RSS footer
        if (!empty($header) && !empty($articles)) {
            $footer = rss_standard_footer();
        }
        //Now, if everything is ok, concatenate it
        if (!empty($header) && !empty($articles) && !empty($footer)) {
            $status = $header.$articles.$footer;
        } else {
            $status = false;
        }
    } else {
        $status = false;
    }

    return $status;
}
