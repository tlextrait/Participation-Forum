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
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot.'/mod/partforum/lib.php');

    $settings->add(new admin_setting_configselect('partforum_displaymode', get_string('displaymode', 'partforum'),
                       get_string('configdisplaymode', 'partforum'), PARTFORUM_MODE_NESTED, partforum_get_layout_modes()));

    $settings->add(new admin_setting_configcheckbox('replytouser', get_string('replytouser', 'partforum'),
                       get_string('configreplytouser', 'partforum'), 1));

    // Less non-HTML characters than this is short
    $settings->add(new admin_setting_configtext('partforum_shortpost', get_string('shortpost', 'partforum'),
                       get_string('configshortpost', 'partforum'), 300, PARAM_INT));

    // More non-HTML characters than this is long
    $settings->add(new admin_setting_configtext('longpost', get_string('longpost', 'partforum'),
                       get_string('configlongpost', 'partforum'), 600, PARAM_INT));

    // Number of discussions on a page
    $settings->add(new admin_setting_configtext('manydiscussions', get_string('manydiscussions', 'partforum'),
                       get_string('configmanydiscussions', 'partforum'), 100, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $settings->add(new admin_setting_configselect('partforum/partforum_maxbytes', get_string('maxattachmentsize', 'partforum'),
                           get_string('configmaxbytes', 'partforum'), 5242880, get_max_upload_sizes($CFG->maxbytes)));
        //get_max_upload_sizes($CFG->maxbytes)
    }

    // Default number of attachments allowed per post in all partforums
    $settings->add(new admin_setting_configtext('partforum_maxattachments', get_string('maxattachments', 'partforum'),
                       get_string('configmaxattachments', 'partforum'), 9, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('partforum_trackreadposts', get_string('trackpartforum', 'partforum'),
                       get_string('configtrackreadposts', 'partforum'), 1));

    // Default number of days that a post is considered old
    $settings->add(new admin_setting_configtext('partforum_oldpostdays', get_string('oldpostdays', 'partforum'),
                       get_string('configoldpostdays', 'partforum'), 14, PARAM_INT));

    // Default whether user needs to mark a post as read
    $settings->add(new admin_setting_configcheckbox('partforum_usermarksread', get_string('usermarksread', 'partforum'),
                       get_string('configusermarksread', 'partforum'), 0));

    $options = array();
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d",$i);
    }
    // Default time (hour) to execute 'clean_read_records' cron
    $settings->add(new admin_setting_configselect('partforum_cleanreadtime', get_string('cleanreadtime', 'partforum'),
                       get_string('configcleanreadtime', 'partforum'), 2, $options));

    // Default time (hour) to send digest email
    $settings->add(new admin_setting_configselect('digestmailtime', get_string('digestmailtime', 'partforum'),
                       get_string('configdigestmailtime', 'partforum'), 17, $options));

    if (empty($CFG->enablerssfeeds)) {
        $options = array(0 => get_string('rssglobaldisabled', 'admin'));
        $str = get_string('configenablerssfeeds', 'partforum').'<br />'.get_string('configenablerssfeedsdisabled2', 'admin');

    } else {
        $options = array(0=>get_string('no'), 1=>get_string('yes'));
        $str = get_string('configenablerssfeeds', 'partforum');
    }
    $settings->add(new admin_setting_configselect('partforum_enablerssfeeds', get_string('enablerssfeeds', 'admin'),
                       $str, 0, $options));

    $settings->add(new admin_setting_configcheckbox('partforum_enabletimedposts', get_string('timedposts', 'partforum'),
                       get_string('configenabletimedposts', 'partforum'), 0));
    
    
    $popsettings = array( 1=>get_string('yes'),0=>get_string('no'),);
    $popupstr = get_string('popupstr','partforum');
    $settings->add(new admin_setting_configselect('partforum_enablepopup', get_string('enablepopup', 'partforum'),
                       $popupstr, 1, $popsettings));
      
    
    $description = '';
    $default =  get_string('partforum_instructions', 'partforum');
    $settings->add(new admin_setting_configtextarea('partforum_instructions', get_string('add_partforum_instructions', 'partforum'), $description, $default,PARAM_RAW, 100, 8));
    
    
    $settings->add(new admin_setting_configcheckbox('partforum_enabletoggle_forallposts', get_string('enabletoggleforallposts', 'partforum'),
                       get_string('configenabletoggleforallposts', 'partforum'), 0));
    
    $settings->add(new admin_setting_configcheckbox('partforum_enablehidelink', get_string('enablehidelink', 'partforum'),
                       get_string('configenablehidelink', 'partforum'), 0));
    
}

