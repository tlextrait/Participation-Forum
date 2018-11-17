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

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/partforum/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all partforums

$url = new moodle_url('/mod/partforum/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = context_course::instance($course->id);


unset($SESSION->fromdiscussion);

add_to_log($course->id, 'partforum', 'view partforums', "index.php?id=$course->id");

$strpartforums       = get_string('partforums', 'partforum');
$strpartforum        = get_string('partforum', 'partforum');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'partforum');
$strsubscribed   = get_string('subscribed', 'partforum');
$strunreadposts  = get_string('unreadposts', 'partforum');
$strtracking     = get_string('tracking', 'partforum');
$strmarkallread  = get_string('markallread', 'partforum');
$strtrackpartforum   = get_string('trackpartforum', 'partforum');
$strnotrackpartforum = get_string('notrackpartforum', 'partforum');
$strsubscribe    = get_string('subscribe', 'partforum');
$strunsubscribe  = get_string('unsubscribe', 'partforum');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');
$strsectionname  = get_string('sectionname', 'format_'.$course->format);

$searchform = partforum_search_form($course);


// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strpartforum, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

if ($usetracking = partforum_tp_can_track_partforums()) {
    $untracked = partforum_tp_get_untracked_partforums($USER->id, $course->id);

    $generaltable->head[] = $strunreadposts;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $strtracking;
    $generaltable->align[] = 'center';
}

$subscribed_partforums = partforum_get_subscribed_partforums($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->partforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->partforum_enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$modinfo =& get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all(); // new function            
//$sections = get_all_sections($course->id); deprecated function

$table = new html_table();

// Parse and organise all the partforums.  Most partforums are course modules but
// some special ones are not.  These get placed in the general partforums
// category with the partforums in section 0.

$partforums = $DB->get_records('partforum', array('course' => $course->id));

$generalpartforums  = array();
$learningpartforums = array();
$modinfo =& get_fast_modinfo($course);

if (!isset($modinfo->instances['partforum'])) {
    $modinfo->instances['partforum'] = array();
}

foreach ($modinfo->instances['partforum'] as $partforumid=>$cm) {
    if (!$cm->uservisible or !isset($partforums[$partforumid])) {
        continue;
    }

    $partforum = $partforums[$partforumid];

    if (!$context = context_module::instance($cm->id)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/partforum:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($partforum->type == 'news' or $partforum->type == 'social') {
        $generalpartforums[$partforum->id] = $partforum;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generalpartforums[$partforum->id] = $partforum;

    } else {
        $learningpartforums[$partforum->id] = $partforum;
    }
}

/// Do course wide subscribe/unsubscribe
if (!is_null($subscribe) and !isguestuser()) {
    foreach ($modinfo->instances['partforum'] as $partforumid=>$cm) {
        $partforum = $partforums[$partforumid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/partforum:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/partforum:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!partforum_is_forcesubscribed($partforum)) {
            $subscribed = partforum_is_subscribed($USER->id, $partforum);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $partforum->forcesubscribe != PARTFORUM_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                partforum_subscribe($USER->id, $partforumid);
            } else if (!$subscribe && $subscribed) {
                partforum_unsubscribe($USER->id, $partforumid);
            }
        }
    }
    $returnto = partforum_go_back_to("index.php?id=$course->id");
    if ($subscribe) {
        add_to_log($course->id, 'partforum', 'subscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallsubscribed', 'partforum', format_string($course->shortname)), 1);
    } else {
        add_to_log($course->id, 'partforum', 'unsubscribeall', "index.php?id=$course->id", $course->id);
        redirect($returnto, get_string('nowallunsubscribed', 'partforum', format_string($course->shortname)), 1);
    }
}

/// First, let's process the general partforums and build up a display

if ($generalpartforums) {
    foreach ($generalpartforums as $partforum) {
        $cm      = $modinfo->instances['partforum'][$partforum->id];
        $context = context_module::instance($cm->id);

        $count = partforum_count_discussions($partforum, $cm, $course);

        if ($usetracking) {
            if ($partforum->trackingtype == PARTFORUM_TRACKING_OFF) {
                $unreadlink  = '-';
                $trackedlink = '-';

            } else {
                if (isset($untracked[$partforum->id])) {
                        $unreadlink  = '-';
                } else if ($unread = partforum_tp_count_partforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$partforum->id.'">'.$unread.'</a>';
                    $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                   $partforum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/clear') . '" alt="'.$strmarkallread.'" /></a></span>';
                } else {
                    $unreadlink = '<span class="read">0</span>';
                }

                if ($partforum->trackingtype == PARTFORUM_TRACKING_ON) {
                    $trackedlink = $stryes;

                } else {
                    $aurl = new moodle_url('/mod/partforum/settracking.php', array('id'=>$partforum->id));
                    if (!isset($untracked[$partforum->id])) {
                        $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackpartforum));
                    } else {
                        $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackpartforum));
                    }
                }
            }
        }

        $partforum->intro = shorten_text(format_module_intro('partforum', $partforum, $cm->id), $CFG->partforum_shortpost);
        $partforumname = format_string($partforum->name, true);;

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $partforumlink = "<a href=\"view.php?f=$partforum->id\" $style>".format_string($partforum->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$partforum->id\" $style>".$count."</a>";

        $row = array ($partforumlink, $partforum->intro, $discussionlink);
        if ($usetracking) {
            $row[] = $unreadlink;
            $row[] = $trackedlink;    // Tracking.
        }

        if ($can_subscribe) {
            if ($partforum->forcesubscribe != PARTFORUM_DISALLOWSUBSCRIBE) {
                $row[] = partforum_get_subscribe_link($partforum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_partforums);
            } else {
                $row[] = '-';
            }
        }

        //If this partforum has RSS activated, calculate it
        if ($show_rss) {
            if ($partforum->rsstype and $partforum->rssarticles) {
                //Calculate the tooltip text
                if ($partforum->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'partforum');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'partforum');
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $USER->id, 'mod_partforum', $partforum->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strpartforum, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

if ($usetracking) {
    $learningtable->head[] = $strunreadposts;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $strtracking;
    $learningtable->align[] = 'center';
}

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($CFG->partforum_enablerssfeeds) &&
                 $CFG->enablerssfeeds && $CFG->partforum_enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning partforums

if ($course->id != SITEID) {    // Only real courses have learning partforums
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningpartforums) {
        $currentsection = '';
            foreach ($learningpartforums as $partforum) {
            $cm      = $modinfo->instances['partforum'][$partforum->id];
            $context = context_module::instance($cm->id);

            $count = partforum_count_discussions($partforum, $cm, $course);

            if ($usetracking) {
                if ($partforum->trackingtype == PARTFORUM_TRACKING_OFF) {
                    $unreadlink  = '-';
                    $trackedlink = '-';

                } else {
                    if (isset($untracked[$partforum->id])) {
                        $unreadlink  = '-';
                    } else if ($unread = partforum_tp_count_partforum_unread_posts($cm, $course)) {
                        $unreadlink = '<span class="unread"><a href="view.php?f='.$partforum->id.'">'.$unread.'</a>';
                        $unreadlink .= '<a title="'.$strmarkallread.'" href="markposts.php?f='.
                                       $partforum->id.'&amp;mark=read"><img src="'.$OUTPUT->pix_url('t/clear') . '" alt="'.$strmarkallread.'" /></a></span>';
                    } else {
                        $unreadlink = '<span class="read">0</span>';
                    }

                    if ($partforum->trackingtype == PARTFORUM_TRACKING_ON) {
                        $trackedlink = $stryes;

                    } else {
                        $aurl = new moodle_url('/mod/partforum/settracking.php', array('id'=>$partforum->id));
                        if (!isset($untracked[$partforum->id])) {
                            $trackedlink = $OUTPUT->single_button($aurl, $stryes, 'post', array('title'=>$strnotrackpartforum));
                        } else {
                            $trackedlink = $OUTPUT->single_button($aurl, $strno, 'post', array('title'=>$strtrackpartforum));
                        }
                    }
                }
            }

            $partforum->intro = shorten_text(format_module_intro('partforum', $partforum, $cm->id), $CFG->partforum_shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $sections[$cm->sectionnum]);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $partforumname = format_string($partforum->name,true);;

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $partforumlink = "<a href=\"view.php?f=$partforum->id\" $style>".format_string($partforum->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$partforum->id\" $style>".$count."</a>";

            $row = array ($printsection, $partforumlink, $partforum->intro, $discussionlink);
            if ($usetracking) {
                $row[] = $unreadlink;
                $row[] = $trackedlink;    // Tracking.
            }

            if ($can_subscribe) {
                if ($partforum->forcesubscribe != PARTFORUM_DISALLOWSUBSCRIBE) {
                    $row[] = partforum_get_subscribe_link($partforum, $context, array('subscribed' => $stryes,
                        'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                        'cantsubscribe' => '-'), false, false, true, $subscribed_partforums);
                } else {
                    $row[] = '-';
                }
            }

            //If this partforum has RSS activated, calculate it
            if ($show_rss) {
                if ($partforum->rsstype and $partforum->rssarticles) {
                    //Calculate the tolltip text
                    if ($partforum->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'partforum');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'partforum');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_partforum', $partforum->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strpartforums);
$PAGE->set_title("$course->shortname: $strpartforums");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

if (!isguestuser()) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/partforum/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'partforum')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/partforum/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'partforum')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generalpartforums) {
    echo $OUTPUT->heading(get_string('generalpartforums', 'partforum'));
    echo html_writer::table($generaltable);
}

if ($learningpartforums) {
    echo $OUTPUT->heading(get_string('learningpartforums', 'partforum'));
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

