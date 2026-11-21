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
 * Unit tests for block_scormreport helper functions.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Helper functions of lib_report.php.
 */
#[Group('block_scormreport')]
#[CoversNothing]
final class lib_report_test extends \advanced_testcase {
    /**
     * Test block_scormreport_is_passed — only 'passed' is truly passed.
     */
    public function test_is_passed(): void {
        $this->assertTrue(block_scormreport_is_passed('passed'));
        $this->assertTrue(block_scormreport_is_passed('Passed'));
        $this->assertTrue(block_scormreport_is_passed(' passed '));

        // 'completed' also counts as passed for percentage/threshold.
        $this->assertTrue(block_scormreport_is_passed('completed'));
        $this->assertTrue(block_scormreport_is_passed('Completed'));

        // These never count as passed.
        $this->assertFalse(block_scormreport_is_passed('failed'));
        $this->assertFalse(block_scormreport_is_passed('incomplete'));
        $this->assertFalse(block_scormreport_is_passed('notattempted'));
        $this->assertFalse(block_scormreport_is_passed(''));
    }

    /**
     * Test block_scormreport_is_completed — passed OR completed.
     */
    public function test_is_completed(): void {
        $this->assertTrue(block_scormreport_is_completed('passed'));
        $this->assertTrue(block_scormreport_is_completed('completed'));
        $this->assertTrue(block_scormreport_is_completed('Completed'));

        $this->assertFalse(block_scormreport_is_completed('failed'));
        $this->assertFalse(block_scormreport_is_completed('incomplete'));
        $this->assertFalse(block_scormreport_is_completed('notattempted'));
    }

    /**
     * Test block_scormreport_hms — time formatting.
     */
    public function test_hms(): void {
        $this->assertEquals('-', block_scormreport_hms(0));
        $this->assertEquals('-', block_scormreport_hms(-5));
        $this->assertEquals('00:00:01', block_scormreport_hms(1));
        $this->assertEquals('00:01:00', block_scormreport_hms(60));
        $this->assertEquals('01:00:00', block_scormreport_hms(3600));
        $this->assertEquals('01:30:45', block_scormreport_hms(5445));
        $this->assertEquals('100:00:00', block_scormreport_hms(360000));
    }

    /**
     * Test block_scormreport_parse_scorm_time — SCORM 1.2 format.
     */
    public function test_parse_scorm_time_12(): void {
        $this->assertEquals(3661, block_scormreport_parse_scorm_time('01:01:01'));
        $this->assertEquals(0, block_scormreport_parse_scorm_time('00:00:00'));
        $this->assertEquals(3600, block_scormreport_parse_scorm_time('01:00:00'));
        $this->assertEquals(90, block_scormreport_parse_scorm_time('00:01:30'));
    }

    /**
     * Test block_scormreport_parse_scorm_time — SCORM 2004 ISO 8601 duration.
     */
    public function test_parse_scorm_time_2004(): void {
        $this->assertEquals(3661, block_scormreport_parse_scorm_time('PT1H1M1S'));
        $this->assertEquals(3600, block_scormreport_parse_scorm_time('PT1H'));
        $this->assertEquals(90, block_scormreport_parse_scorm_time('PT1M30S'));
        $this->assertEquals(30, block_scormreport_parse_scorm_time('PT30S'));
        $this->assertEquals(0, block_scormreport_parse_scorm_time('PT0S'));
        $this->assertEquals(5445, block_scormreport_parse_scorm_time('PT1H30M45S'));
    }

    /**
     * Test block_scormreport_parse_scorm_time — edge cases.
     */
    public function test_parse_scorm_time_edge(): void {
        $this->assertEquals(0, block_scormreport_parse_scorm_time(''));
        $this->assertEquals(0, block_scormreport_parse_scorm_time('not a time'));
        $this->assertEquals(0, block_scormreport_parse_scorm_time(null));
    }

    /**
     * Test block_scormreport_get_badge_info — all statuses.
     */
    public function test_badge_info(): void {
        $passed = block_scormreport_get_badge_info('passed');
        $this->assertEquals('badge-success', $passed['badge']);

        $completed = block_scormreport_get_badge_info('completed');
        $this->assertEquals('badge-info', $completed['badge']);

        $failed = block_scormreport_get_badge_info('failed');
        $this->assertEquals('badge-danger', $failed['badge']);

        $incomplete = block_scormreport_get_badge_info('incomplete');
        $this->assertEquals('badge-warning', $incomplete['badge']);

        $na = block_scormreport_get_badge_info('notattempted');
        $this->assertEquals('badge-secondary', $na['badge']);

        // Unknown defaults to notattempted
        $unknown = block_scormreport_get_badge_info('something');
        $this->assertEquals('badge-secondary', $unknown['badge']);
    }

    /**
     * Test block_scormreport_calc_status — SCORM 2004 priority.
     */
    public function test_calc_status_scorm2004(): void {
        $elementids = [
            'cmi.success_status'    => 'ss',
            'cmi.completion_status' => 'cs',
            'cmi.core.lesson_status' => 'ls',
        ];

        // success_status takes priority
        $values = ['ss' => 'passed', 'cs' => 'incomplete'];
        $this->assertEquals('passed', block_scormreport_calc_status($values, $elementids));

        $values = ['ss' => 'failed', 'cs' => 'completed'];
        $this->assertEquals('failed', block_scormreport_calc_status($values, $elementids));

        // If success_status is empty/unknown, fall to completion_status
        $values = ['ss' => 'unknown', 'cs' => 'completed'];
        $this->assertEquals('completed', block_scormreport_calc_status($values, $elementids));

        // If nothing, fall to incomplete
        $values = [];
        $this->assertEquals('incomplete', block_scormreport_calc_status($values, $elementids));
    }

    /**
     * Test block_scormreport_calc_status — SCORM 1.2.
     */
    public function test_calc_status_scorm12(): void {
        $elementids = [
            'cmi.core.lesson_status' => 'ls',
        ];

        $values = ['ls' => 'passed'];
        $this->assertEquals('passed', block_scormreport_calc_status($values, $elementids));

        $values = ['ls' => 'completed'];
        $this->assertEquals('completed', block_scormreport_calc_status($values, $elementids));

        $values = ['ls' => 'failed'];
        $this->assertEquals('failed', block_scormreport_calc_status($values, $elementids));
    }

    /**
     * Test block_scormreport_calc_deadline_status.
     */
    public function test_deadline_status(): void {
        $now = time();
        $start = $now - (30 * 86400); // Started 30 days ago
        $end = $now + (70 * 86400);   // 70 days remaining = 100 day course, 30% elapsed

        // Student at 50% progress, 30% elapsed → green (ahead)
        $result = block_scormreport_calc_deadline_status(50, $start, $end, 15);
        $this->assertEquals('ontrack', $result['status']);

        // Student at 10% progress, 30% elapsed → gap=20 > 15 → red
        $result = block_scormreport_calc_deadline_status(10, $start, $end, 15);
        $this->assertEquals('atrisk', $result['status']);

        // Student at 20% progress, 30% elapsed → gap=10 < 15 → yellow
        $result = block_scormreport_calc_deadline_status(20, $start, $end, 15);
        $this->assertEquals('tight', $result['status']);

        // No end date
        $result = block_scormreport_calc_deadline_status(50, $start, 0, 15);
        $this->assertEquals('nodate', $result['status']);

        // Deadline passed
        $pastend = $now - 86400;
        $result = block_scormreport_calc_deadline_status(50, $start, $pastend, 15);
        $this->assertEquals('finished', $result['status']);
    }

    /**
     * Test block_scormreport_escape_spreadsheet — formula injection.
     */
    public function test_escape_spreadsheet(): void {
        $this->assertEquals("'=SUM(A1)", block_scormreport_escape_spreadsheet('=SUM(A1)'));
        $this->assertEquals("'+cmd", block_scormreport_escape_spreadsheet('+cmd'));
        $this->assertEquals("'-calc", block_scormreport_escape_spreadsheet('-calc'));
        $this->assertEquals("'@evil", block_scormreport_escape_spreadsheet('@evil'));
        $this->assertEquals('Normal text', block_scormreport_escape_spreadsheet('Normal text'));
        $this->assertEquals('123', block_scormreport_escape_spreadsheet('123'));
    }

    /**
     * Test block_scormreport_get_connections with no logs returns zero.
     */
    public function test_connections_empty(): void {
        $this->resetAfterTest(true);
        $result = block_scormreport_get_connections(1, []);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test block_scormreport_get_connections initializes structure per user.
     */
    public function test_connections_structure(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $result = block_scormreport_get_connections($course->id, [$user->id]);
        $this->assertArrayHasKey($user->id, $result);
        $this->assertArrayHasKey('sessions', $result[$user->id]);
        $this->assertArrayHasKey('total_seconds', $result[$user->id]);
        $this->assertEquals(0, $result[$user->id]['sessions']);
    }

    /**
     * Test block_scormreport_get_interactions with empty input.
     */
    public function test_interactions_empty(): void {
        global $DB;
        $result = block_scormreport_get_interactions([], $DB);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that the result element parsing regex handles SCORM interaction
     * element names correctly (index extraction and field extraction).
     */
    public function test_interaction_element_parsing(): void {
        // Verify the regex used in block_scormreport_get_interactions works for
        // the real Moodle 5 element names (with underscores).
        $samples = [
            'cmi.interactions_0.id'                        => ['0', 'id'],
            'cmi.interactions_10.student_response'         => ['10', 'student_response'],
            'cmi.interactions_5.learner_response'          => ['5', 'learner_response'],
            'cmi.interactions_6.correct_responses_0.pattern' => ['6', 'correct_responses_0.pattern'],
            'cmi.interactions_3.result'                    => ['3', 'result'],
        ];
        foreach ($samples as $element => $expected) {
            $matched = preg_match('/^cmi\\.interactions_(\\d+)\\.(.+)$/', $element, $m);
            $this->assertEquals(1, $matched, "Failed to match: $element");
            $this->assertEquals($expected[0], $m[1], "Wrong index for: $element");
            $this->assertEquals($expected[1], $m[2], "Wrong field for: $element");
        }
    }

    /**
     * Test that a non-interaction element does NOT match the regex.
     */
    public function test_interaction_regex_rejects_other(): void {
        $others = ['cmi.core.total_time', 'cmi.score.raw', 'cmi.completion_status'];
        foreach ($others as $element) {
            $matched = preg_match('/^cmi\\.interactions_(\\d+)\\.(.+)$/', $element, $m);
            $this->assertEquals(0, $matched, "Should not match: $element");
        }
    }

    /**
     * Test correct_responses pattern detection (single and multiple).
     */
    public function test_correct_responses_pattern(): void {
        $single = 'correct_responses_0.pattern';
        $this->assertEquals(1, preg_match('/^correct_responses_\\d+\\.pattern$/', $single));

        $multi = 'correct_responses_4.pattern';
        $this->assertEquals(1, preg_match('/^correct_responses_\\d+\\.pattern$/', $multi));

        $notpattern = 'id';
        $this->assertEquals(0, preg_match('/^correct_responses_\\d+\\.pattern$/', $notpattern));
    }
    /**
     * Connection totals remain correct when a session crosses a 90-day SQL window.
     */
    public function test_connections_across_query_window(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $base = 1700000000;
        $events = [$base, $base + 600, $base + 90 * DAYSECS - 60,
            $base + 90 * DAYSECS + 60, $base + 90 * DAYSECS + 3600];
        foreach ($events as $time) {
            $DB->insert_record('logstore_standard_log', (object)[
                'eventname' => '\\core\\event\\course_viewed',
                'component' => 'core',
                'action' => 'viewed',
                'target' => 'course',
                'objecttable' => 'course',
                'objectid' => $course->id,
                'crud' => 'r',
                'edulevel' => 2,
                'contextid' => \context_course::instance($course->id)->id,
                'contextlevel' => CONTEXT_COURSE,
                'contextinstanceid' => $course->id,
                'userid' => $user->id,
                'courseid' => $course->id,
                'relateduserid' => null,
                'anonymous' => 0,
                'other' => null,
                'timecreated' => $time,
                'origin' => 'web',
                'ip' => '127.0.0.1',
                'realuserid' => null,
            ]);
        }
        $data = block_scormreport_get_connections($course->id, [$user->id]);
        $this->assertSame(3, $data[$user->id]['sessions']);
        $this->assertSame(720, $data[$user->id]['total_seconds']);
        $this->assertSame($base, $data[$user->id]['first_login']);
        $this->assertSame(end($events), $data[$user->id]['last_login']);
        // Repeated IDs, including across the 500-user chunks, must not add duration twice.
        $studentids = array_merge([$user->id], range($user->id + 1, $user->id + 499), [$user->id]);
        $withduplicates = block_scormreport_get_connections($course->id, $studentids);
        $this->assertSame($data[$user->id], $withduplicates[$user->id]);
    }
}
