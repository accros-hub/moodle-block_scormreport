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
 * SCORM Report block - Sidebar block displaying SCORM course reports.
 * - Teacher/admin: link to the complete report for all students.
 * - Student: link to their own personal report.
 * Shows a link to the SCORM report for teachers and a progress link for students.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_scormreport extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_scormreport');
    }

    public function applicable_formats() {
        return ['course-view' => true];
    }

    public function has_config() {
        return true;
    }

    public function get_content() {
        global $COURSE, $USER, $CFG, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content         = new stdClass();
        $this->content->footer = '';

        $context = context_course::instance($COURSE->id);

        // Teacher/admin view
        if (has_capability('block/scormreport:viewreports', $context)) {
            $reporturl = new moodle_url('/blocks/scormreport/report.php',
                ['courseid' => $COURSE->id]);

            $this->content->text = html_writer::div(
                html_writer::tag('p', get_string('reportdesc', 'block_scormreport')) .
                html_writer::link(
                    $reporturl,
                    get_string('viewreport', 'block_scormreport'),
                    ['class' => 'btn btn-primary btn-sm mt-2', 'target' => '_blank']
                ),
                'block-scormreport-content p-2'
            );
            return $this->content;
        }

        // Student view. Hidden when the license does not cover this course: students cannot
        // do anything about it and the locked page is meant for teachers and administrators.
        require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');
        if (has_capability('block/scormreport:viewownreport', $context)
                && \block_scormreport\license::course_allowed($COURSE)) {
            $studenturl = new moodle_url('/blocks/scormreport/student.php',
                ['courseid' => $COURSE->id]);

            $this->content->text = html_writer::div(
                html_writer::tag('p', get_string('studentreportdesc', 'block_scormreport')) .
                html_writer::link(
                    $studenturl,
                    get_string('viewmyreport', 'block_scormreport'),
                    ['class' => 'btn btn-info btn-sm mt-2', 'target' => '_blank']
                ),
                'block-scormreport-content p-2'
            );
            return $this->content;
        }

        $this->content->text = '';
        return $this->content;
    }
}
