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
 * Block ace_quests — shows the user's active quests.
 *
 * On the site dashboard it shows quests across all courses.
 * Inside a course it shows quests for that course only.
 *
 * @package    block_ace_quests
 * @copyright  2026 Letstudy Group
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * ACE quests block.
 *
 * Displays active quests for the user from the local_aceengine plugin
 * on course pages and the user dashboard.
 *
 * @package    block_ace_quests
 * @copyright  2026 Letstudy Group
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ace_quests extends block_base {
    /**
     * Initialise the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_ace_quests');
    }

    /**
     * Which page formats this block can be added to.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'my' => true,
            'site-index' => true,
            'course-view' => true,
        ];
    }

    /**
     * Only one instance per page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * No global config.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Build the block content.
     *
     * @return stdClass The block content object.
     */
    public function get_content() {
        global $USER, $CFG;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }

        if (!get_config('local_aceengine', 'enableplugin')) {
            return $this->content;
        }

        require_once($CFG->dirroot . '/local/ace/lib.php');

        $userid = $USER->id;
        $courseid = $this->page->course->id;
        $issitecontext = ($courseid == SITEID);

        if ($issitecontext) {
            $data = $this->get_all_courses_data($userid);
            $this->content->footer = html_writer::link(
                new moodle_url('/local/ace/my_quests.php'),
                get_string('viewallquests', 'block_ace_quests'),
                ['class' => 'btn btn-outline-primary btn-sm btn-block mt-2']
            );
        } else {
            if (!local_aceengine_is_enabled_for_course($courseid)) {
                return $this->content;
            }
            $data = $this->get_single_course_data($userid, $courseid);
            $this->content->footer = html_writer::link(
                new moodle_url('/local/ace/index.php', ['courseid' => $courseid]),
                get_string('viewallquests', 'block_ace_quests'),
                ['class' => 'btn btn-outline-primary btn-sm btn-block mt-2']
            );
        }

        $this->content->text = $this->page->get_renderer('core')->render_from_template(
            'block_ace_quests/block_content',
            $data
        );

        return $this->content;
    }

    /**
     * Load quests for a single course.
     *
     * @param int $userid The user ID.
     * @param int $courseid The course ID.
     * @return array Template data.
     */
    private function get_single_course_data(int $userid, int $courseid): array {
        global $DB;

        $activequests = $DB->get_records('local_aceengine_quests', [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'active',
        ], 'timecreated DESC');

        $renderer = $this->page->get_renderer('local_aceengine');
        $questcards = [];
        foreach ($activequests as $quest) {
            $card = new \local_aceengine\output\quest_card($quest);
            $questcards[] = $card->export_for_template($renderer);
        }

        $xprecord = $DB->get_record('local_aceengine_xp', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        return [
            'issitecontext' => false,
            'quests' => $questcards,
            'hasquests' => !empty($questcards),
            'xp' => $xprecord ? (int) $xprecord->xp : 0,
            'level' => $xprecord ? (int) $xprecord->level : 1,
            'courses' => [],
            'hascourses' => false,
        ];
    }

    /**
     * Load quests across all enrolled courses for the site dashboard.
     *
     * @param int $userid The user ID.
     * @return array Template data.
     */
    private function get_all_courses_data(int $userid): array {
        global $DB;

        $enrolledcourses = enrol_get_users_courses($userid, true, 'id, fullname, shortname');
        $renderer = $this->page->get_renderer('local_aceengine');

        $courses = [];
        $totalxp = 0;
        $totalquests = 0;

        foreach ($enrolledcourses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            if (!local_aceengine_is_enabled_for_course($course->id)) {
                continue;
            }

            $activequests = $DB->get_records('local_aceengine_quests', [
                'userid' => $userid,
                'courseid' => $course->id,
                'status' => 'active',
            ], 'timecreated DESC');

            if (empty($activequests)) {
                continue;
            }

            $questcards = [];
            foreach ($activequests as $quest) {
                $card = new \local_aceengine\output\quest_card($quest);
                $questcards[] = $card->export_for_template($renderer);
            }

            $xprecord = $DB->get_record('local_aceengine_xp', [
                'userid' => $userid,
                'courseid' => $course->id,
            ]);

            $xp = $xprecord ? (int) $xprecord->xp : 0;
            $totalxp += $xp;
            $totalquests += count($questcards);

            $courses[] = [
                'courseid' => $course->id,
                'coursename' => format_string($course->fullname),
                'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'xp' => $xp,
                'level' => $xprecord ? (int) $xprecord->level : 1,
                'quests' => $questcards,
                'questcount' => count($questcards),
            ];
        }

        return [
            'issitecontext' => true,
            'courses' => $courses,
            'hascourses' => !empty($courses),
            'totalxp' => $totalxp,
            'totalquests' => $totalquests,
            'quests' => [],
            'hasquests' => false,
        ];
    }
}
