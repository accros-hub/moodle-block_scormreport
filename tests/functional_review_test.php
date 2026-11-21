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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Integration checks for MMRT-293.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

use core\task\manager;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Exercise the plugin against a disposable Moodle test installation.
 */
#[CoversNothing]
final class functional_review_test extends \advanced_testcase {
    /**
     * The Moodle course copy API backs up and restores exactly one history row.
     */
    public function test_course_copy_restores_notification_history(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/externallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['visible' => 0]);
        $recipient = $generator->create_user(['suspended' => 1]);
        $sender = $generator->create_user();
        $context = \context_course::instance($course->id);
        $generator->create_block('scormreport', [
            'parentcontextid' => $context->id,
            'pagetypepattern' => 'course-view-*',
        ]);
        $original = [
            'courseid' => $course->id,
            'userid' => $recipient->id,
            'senderid' => $sender->id,
            'channel' => 'internal',
            'reason' => 'MMRT-293 notification history',
            'progress' => 50,
            'threshold' => 75,
            'status' => 'sent',
            'timecreated' => 1700000000,
        ];
        $oldid = $DB->insert_record('block_scormreport_notif', (object)$original);

        $copy = \core_course_external::duplicate_course(
            $course->id,
            'MMRT-293 restored',
            'mmrt293-restored',
            $course->category,
            0,
            [['name' => 'users', 'value' => 1], ['name' => 'blocks', 'value' => 1]],
        );
        $this->assertNotEquals($course->id, $copy['id']);
        $this->assertEquals(0, $DB->get_field('course', 'visible', ['id' => $copy['id']]));
        $restored = $DB->get_records('block_scormreport_notif', ['courseid' => $copy['id']]);
        $this->assertCount(1, $restored);
        $row = reset($restored);
        $this->assertNotEquals($oldid, $row->id);
        foreach ($original as $field => $value) {
            $this->assertEquals($field === 'courseid' ? $copy['id'] : $value, $row->$field, $field);
        }
        $this->assertEquals(1, $DB->count_records('block_scormreport_notif', ['courseid' => $course->id]));

        $withoutusers = \core_course_external::duplicate_course(
            $course->id,
            'MMRT-293 restored without users',
            'mmrt293-no-users',
            $course->category,
            0,
            [['name' => 'users', 'value' => 0], ['name' => 'blocks', 'value' => 1]],
        );
        $this->assertFalse($DB->record_exists('block_scormreport_notif', ['courseid' => $withoutusers['id']]));
    }

    /**
     * Produce a real multi-page PDF from the shared report pagination helper.
     */
    public function test_pdf_paginates_long_report_cells(): void {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        require_once($CFG->dirroot . '/blocks/scormreport/paginacion_fix.php');

        $pdf = new \pdf('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $headers = 0;
        block_scormreport_pdf_draw_paginated_row(
            $pdf,
            [['w' => 55, 'text' => str_repeat("MMRT-293: Learner progress report.\n", 180)]],
            static function () use ($pdf, &$headers) {
                $pdf->AddPage();
                $headers++;
            },
        );
        $pages = $pdf->getNumPages();
        $this->assertGreaterThan(1, $pages);
        $this->assertSame($pages - 1, $headers);
        $this->assertStringStartsWith('%PDF-', $pdf->Output('report.pdf', 'S'));
    }

    /**
     * Render both templates using Moodle's actual Output API.
     */
    public function test_output_escapes_course_names_and_preserves_form_controls(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $output = $PAGE->get_renderer('core');
        $name = 'Course <strong>MMRT-293</strong> & more';
        $dashboard = $output->render_from_template('block_scormreport/compliance_dashboard', [
            'title' => 'Compliance',
            'filters' => [['label' => 'Recent', 'url' => '/?filter=recent']],
            'headers' => [['label' => 'Course']],
            'courses' => [['coursename' => $name, 'reporturl' => '/?courseid=42&view=detail']],
        ]);
        $this->assertStringContainsString('Course &lt;strong&gt;MMRT-293&lt;/strong&gt; &amp; more', $dashboard);
        $this->assertStringContainsString('href="/?courseid=42&amp;view=detail"', $dashboard);
        $this->assertStringNotContainsString($name, $dashboard);

        $reminder = $output->render_from_template('block_scormreport/reminder_form', [
            'title' => 'Reminder',
            'formurl' => '/blocks/scormreport/send_reminder.php?courseid=42',
            'courseid' => 42,
            'sesskey' => sesskey(),
            'hasrecipients' => true,
            'recipients' => [['userid' => 50, 'studentname' => $name]],
            'percentoptions' => [['value' => 50, 'label' => '50%', 'selected' => true]],
            'channels' => [['value' => 'internal', 'label' => 'Internal', 'selected' => true]],
        ]);
        $this->assertStringContainsString('method="post"', $reminder);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $reminder);
        $this->assertStringContainsString('name="selected_users[]"', $reminder);
        $this->assertStringContainsString('id="preview-box"', $reminder);
        $this->assertStringContainsString('Course &lt;strong&gt;MMRT-293&lt;/strong&gt; &amp; more', $reminder);
    }

    /**
     * Run both scheduled tasks with an empty license and mail/message sinks active.
     */
    public function test_scheduled_tasks_do_not_send_when_disabled_or_unlicensed(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Even an unexpected refresh can only contact the local test runner.
        $CFG->block_scormreport_apiurl = 'http://127.0.0.1:1/';
        unset_config('lic_token', 'block_scormreport');
        unset_config('lic_lasttoken', 'block_scormreport');
        unset_config('lic_testmode', 'block_scormreport');
        license::reset_caches();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - DAYSECS, 'enddate' => 0]);
        helper::add_block_to_course($course->id);
        $reminders = manager::get_scheduled_task(task\send_auto_reminders::class);
        $refresh = manager::get_scheduled_task(task\refresh_license::class);
        $this->assertInstanceOf(task\send_auto_reminders::class, $reminders);
        $this->assertInstanceOf(task\refresh_license::class, $refresh);
        $messages = $this->redirectMessages();
        $emails = $this->redirectEmails();
        ob_start();
        try {
            set_config('autoreminder_enabled', 0, 'block_scormreport');
            $reminders->execute();
            set_config('autoreminder_enabled', 1, 'block_scormreport');
            $reminders->execute();
            $refresh->execute();
        } finally {
            $trace = ob_get_clean();
            $messages->close();
            $emails->close();
        }
        $this->assertStringContainsString('auto-reminders disabled', $trace);
        $this->assertStringContainsString('not covered by the license', $trace);
        $this->assertStringContainsString('license refresh not due', $trace);
        $this->assertSame(0, $messages->count());
        $this->assertSame(0, $emails->count());
        $this->assertFalse($DB->record_exists('block_scormreport_notif', ['courseid' => $course->id]));
    }

    /**
     * Capture a scheduled reminder in memory and verify the second run observes the cooldown.
     */
    public function test_scheduled_reminder_is_captured_and_cooldown_prevents_duplicates(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('lic_testmode', 1, 'block_scormreport');
        set_config('autoreminder_enabled', 1, 'block_scormreport');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['startdate' => time() - DAYSECS, 'enddate' => 0]);
        $learner = $generator->create_user();
        $generator->enrol_user($learner->id, $course->id, 'student');
        $generator->create_module('scorm', ['course' => $course->id]);
        helper::add_block_to_course($course->id);

        $messages = $this->redirectMessages();
        $emails = $this->redirectEmails();
        ob_start();
        try {
            $task = manager::get_scheduled_task(task\send_auto_reminders::class);
            $task->execute();
            $task->execute();
        } finally {
            ob_end_clean();
            $messages->close();
            $emails->close();
        }
        $this->assertSame(1, $messages->count());
        $this->assertSame(0, $emails->count());
        $rows = $DB->get_records('block_scormreport_notif', ['courseid' => $course->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals($learner->id, $row->userid);
        $this->assertSame('auto', $row->channel);
        $this->assertSame('sent', $row->status);
    }
}
