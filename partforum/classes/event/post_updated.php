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
 * The mod_partforum post updated event.
 *
 * @package    mod_partforum
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_partforum\event;

defined('MOODLE_INTERNAL') || die();

/**
 * The mod_partforum post updated event class.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int discussionid: The discussion id the post is part of.
 *      - int partforumid: The partforum id the post is part of.
 *      - string partforumtype: The type of partforum the post is part of.
 * }
 *
 * @package    mod_partforum
 * @since      Moodle 2.7
 * @copyright  2014 Dan Poltawski <dan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class post_updated extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'partforum_posts';
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' has updated the post with id '$this->objectid' in the discussion with " .
            "id '{$this->other['discussionid']}' in the partforum with course module id '$this->contextinstanceid'.";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventpostupdated', 'mod_partforum');
    }

    /**
     * Get URL related to the action
     *
     * @return \moodle_url
     */
    public function get_url() {
        if ($this->other['partforumtype'] == 'single') {
            // Single discussion partforums are an exception. We show
            // the partforum itself since it only has one discussion
            // thread.
            $url = new \moodle_url('/mod/partforum/view.php', array('f' => $this->other['partforumid']));
        } else {
            $url = new \moodle_url('/mod/partforum/discuss.php', array('d' => $this->other['discussionid']));
        }
        $url->set_anchor('p'.$this->objectid);
        return $url;
    }

    /**
     * Return the legacy event log data.
     *
     * @return array|null
     */
    protected function get_legacy_logdata() {
        // The legacy log table expects a relative path to /mod/partforum/.
        $logurl = substr($this->get_url()->out_as_local_url(), strlen('/mod/partforum/'));

        return array($this->courseid, 'partforum', 'update post', $logurl, $this->objectid, $this->contextinstanceid);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['discussionid'])) {
            throw new \coding_exception('The \'discussionid\' value must be set in other.');
        }

        if (!isset($this->other['partforumid'])) {
            throw new \coding_exception('The \'partforumid\' value must be set in other.');
        }

        if (!isset($this->other['partforumtype'])) {
            throw new \coding_exception('The \'partforumtype\' value must be set in other.');
        }

        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }

    public static function get_objectid_mapping() {
        return array('db' => 'partforum_posts', 'restore' => 'partforum_post');
    }

    public static function get_other_mapping() {
        $othermapped = array();
        $othermapped['partforumid'] = array('db' => 'partforum', 'restore' => 'partforum');
        $othermapped['discussionid'] = array('db' => 'partforum_discussions', 'restore' => 'partforum_discussion');

        return $othermapped;
    }
}
