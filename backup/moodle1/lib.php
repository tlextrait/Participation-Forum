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
 * Provides support for the conversion of moodle1 backup to the moodle2 format
 *
 * @package    mod
 * @subpackage forum
 * @copyright  2011 Mark Nielsen <mark@moodlerooms.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Forum conversion handler
 */
class moodle1_mod_partforum_handler extends moodle1_mod_handler {

    /** @var moodle1_file_manager */
    protected $fileman = null;

    /** @var int cmid */
    protected $moduleid = null;

    /**
     * Declare the paths in moodle.xml we are able to convert
     *
     * The method returns list of {@link convert_path} instances.
     * For each path returned, the corresponding conversion method must be
     * defined.
     *
     * Note that the paths /MOODLE_BACKUP/COURSE/MODULES/MOD/PARTFORUM do not
     * actually exist in the file. The last element with the module name was
     * appended by the moodle1_converter class.
     *
     * @return array of {@link convert_path} instances
     */
    public function get_paths() {
        return array(
            new convert_path('partforum', '/MOODLE_BACKUP/COURSE/MODULES/MOD/PARTFORUM',
                array(
                    'renamefields' => array(
                        'format' => 'messageformat',
                    ),
                    'newfields' => array(
                        'completiondiscussions' => 0,
                        'completionreplies' => 0,
                        'completionpost' => 0,
                        'maxattachments' => 1,
                        'introformat' => 0,
                    ),
                )
            ),
        );
    }

    /**
     * Converts /MOODLE_BACKUP/COURSE/MODULES/MOD/PARTFORUM data
     */
    public function process_partforum($data) {
        // get the course module id and context id
        $instanceid     = $data['id'];
        $cminfo         = $this->get_cminfo($instanceid);
        $this->moduleid = $cminfo['id'];
        $contextid      = $this->converter->get_contextid(CONTEXT_MODULE, $this->moduleid);

        // get a fresh new file manager for this instance
        $this->fileman = $this->converter->get_file_manager($contextid, 'mod_partforum');

        // convert course files embedded into the intro
        $this->fileman->filearea = 'intro';
        $this->fileman->itemid   = 0;
        $data['intro'] = moodle1_converter::migrate_referenced_files($data['intro'], $this->fileman);

        // start writing forum.xml
        $this->open_xml_writer("activities/partforum_{$this->moduleid}/partforum.xml");
        $this->xmlwriter->begin_tag('activity', array('id' => $instanceid, 'moduleid' => $this->moduleid,
            'modulename' => 'partforum', 'contextid' => $contextid));
        $this->xmlwriter->begin_tag('partforum', array('id' => $instanceid));

        foreach ($data as $field => $value) {
            if ($field <> 'id') {
                $this->xmlwriter->full_tag($field, $value);
            }
        }

        $this->xmlwriter->begin_tag('discussions');

        return $data;
    }

    /**
     * This is executed when we reach the closing </MOD> tag of our 'partforum' path
     */
    public function on_forum_end() {
        // finish writing forum.xml
        $this->xmlwriter->end_tag('discussions');
        $this->xmlwriter->end_tag('partforum');
        $this->xmlwriter->end_tag('activity');
        $this->close_xml_writer();

        // write inforef.xml
        $this->open_xml_writer("activities/partforum_{$this->moduleid}/inforef.xml");
        $this->xmlwriter->begin_tag('inforef');
        $this->xmlwriter->begin_tag('fileref');
        foreach ($this->fileman->get_fileids() as $fileid) {
            $this->write_xml('file', array('id' => $fileid));
        }
        $this->xmlwriter->end_tag('fileref');
        $this->xmlwriter->end_tag('inforef');
        $this->close_xml_writer();
    }
}
