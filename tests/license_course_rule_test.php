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
 * Tests for the license rule by course start date, using signed ACCROS3 tokens.
 *
 * Fixtures are signed with a test-only key pair; only its public half is stored.
 * None of these tests depends on the current date: the valid token ends in 2099
 * and the expired one ended in 2023.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_license_rule.php');

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * license::course_allowed(), has_any_access(), licensed_until() and test mode.
 */
#[Group('block_scormreport')]
#[CoversClass(license::class)]
final class license_course_rule_test extends \advanced_testcase {
    /** End date of the expired fixture token (2023-11-14). */
    private const EXPIRED_END = 1700000000;

    /** End date of the valid fixture token (2099-12-31). */
    private const VALID_END = 4070908799;

    /**
     * Read a fixture file.
     *
     * @param string $name File name in tests/fixtures.
     * @return string
     */
    private function fixture(string $name): string {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        $this->assertNotFalse($contents);
        return trim($contents);
    }

    /**
     * Store the license state the way the plugin does and clear the caches.
     *
     * @param string $token Current token ('' for none).
     * @param string $lasttoken Last token the site held ('' for none).
     * @param array $extra Other plugin settings (lic_clearedat, lic_blocked, lic_testmode).
     * @return void
     */
    private function set_state(string $token, string $lasttoken, array $extra = []): void {
        $this->resetAfterTest();
        set_config('lic_token', $token, 'block_scormreport');
        set_config('lic_lasttoken', $lasttoken, 'block_scormreport');
        foreach (['lic_clearedat', 'lic_blocked', 'lic_testmode'] as $name) {
            if (array_key_exists($name, $extra)) {
                set_config($name, $extra[$name], 'block_scormreport');
            } else {
                unset_config($name, 'block_scormreport');
            }
        }
        testable_license_rule::reset_caches();
    }

    /**
     * Course record with the given start date.
     *
     * @param int $start Start date (0 = none).
     * @param int|null $created Creation date, or null to leave it out.
     * @return \stdClass
     */
    private function course(int $start, ?int $created = null): \stdClass {
        $course = (object)['id' => 0, 'startdate' => $start];
        if ($created !== null) {
            $course->timecreated = $created;
        }
        return $course;
    }

    public function test_valid_license_allows_every_course(): void {
        $token = $this->fixture('v3_valid_token.txt');
        $this->set_state($token, $token);
        $this->assertTrue(testable_license_rule::is_valid());
        $this->assertTrue(testable_license_rule::course_allowed($this->course(1000000000)));
        $this->assertTrue(testable_license_rule::course_allowed($this->course(time() + 365 * DAYSECS)));
        $this->assertTrue(testable_license_rule::has_any_access());
        $this->assertSame(self::VALID_END, testable_license_rule::licensed_until());
    }

    public function test_ended_license_keeps_courses_started_before_the_end(): void {
        $this->set_state('', $this->fixture('v3_expired_token.txt'));
        $this->assertFalse(testable_license_rule::is_valid());
        $this->assertSame(self::EXPIRED_END, testable_license_rule::licensed_until());
        $this->assertTrue(testable_license_rule::course_allowed($this->course(self::EXPIRED_END - DAYSECS)));
        $this->assertTrue(testable_license_rule::course_allowed($this->course(self::EXPIRED_END)));
        $this->assertTrue(testable_license_rule::has_any_access());
    }

    public function test_ended_license_locks_courses_started_after_the_end(): void {
        $this->set_state('', $this->fixture('v3_expired_token.txt'));
        $this->assertFalse(testable_license_rule::course_allowed($this->course(self::EXPIRED_END + 1)));
        $this->assertFalse(testable_license_rule::course_allowed($this->course(time())));
    }

    public function test_course_without_start_date_uses_creation_date(): void {
        $this->set_state('', $this->fixture('v3_expired_token.txt'));
        $this->assertTrue(testable_license_rule::course_allowed($this->course(0, self::EXPIRED_END - DAYSECS)));
        $this->assertFalse(testable_license_rule::course_allowed($this->course(0, self::EXPIRED_END + DAYSECS)));
        // No start date and no creation date: not covered.
        $this->assertFalse(testable_license_rule::course_allowed($this->course(0)));
    }

    public function test_suspended_or_revoked_license_locks_every_course(): void {
        foreach (['suspended', 'revoked'] as $reason) {
            $this->set_state('', $this->fixture('v3_expired_token.txt'), ['lic_blocked' => $reason]);
            $this->assertSame($reason, testable_license_rule::blocked_reason());
            $this->assertFalse(testable_license_rule::course_allowed($this->course(self::EXPIRED_END - DAYSECS)));
            $this->assertFalse(testable_license_rule::has_any_access());
        }
    }

    public function test_blocked_license_overrides_valid_token(): void {
        foreach (["suspended", "revoked"] as $reason) {
            $token = $this->fixture("v3_valid_token.txt");
            $this->set_state($token, $token, ["lic_blocked" => $reason]);
            $this->assertFalse(testable_license_rule::is_valid());
            $this->assertFalse(testable_license_rule::course_allowed($this->course(time())));
            $this->assertFalse(testable_license_rule::has_any_access());
        }
    }

    public function test_unknown_blocked_value_is_ignored(): void {
        $this->set_state('', $this->fixture('v3_expired_token.txt'), ['lic_blocked' => 'whatever']);
        $this->assertSame('', testable_license_rule::blocked_reason());
        $this->assertTrue(testable_license_rule::course_allowed($this->course(self::EXPIRED_END - DAYSECS)));
    }

    public function test_license_ended_early_uses_the_date_it_was_cleared(): void {
        // A valid license removed or stopped before its end date: the cut-off is the removal date.
        $cleared = 1750000000;
        $this->set_state('', $this->fixture('v3_valid_token.txt'), ['lic_clearedat' => $cleared]);
        $this->assertSame($cleared, testable_license_rule::licensed_until());
        $this->assertTrue(testable_license_rule::course_allowed($this->course($cleared - DAYSECS)));
        $this->assertFalse(testable_license_rule::course_allowed($this->course($cleared + DAYSECS)));
    }

    public function test_tokens_for_another_site_or_product_give_no_access(): void {
        foreach (['v3_wrong_site_token.txt', 'v3_wrong_product_token.txt'] as $name) {
            $token = $this->fixture($name);
            $this->set_state($token, $token);
            $this->assertFalse(testable_license_rule::is_valid());
            $this->assertSame(0, testable_license_rule::licensed_until());
            $this->assertFalse(testable_license_rule::course_allowed($this->course(1000000000)));
            $this->assertFalse(testable_license_rule::has_any_access());
        }
    }

    public function test_never_licensed_site_locks_every_course(): void {
        $this->set_state('', '');
        $this->assertSame(0, testable_license_rule::licensed_until());
        $this->assertFalse(testable_license_rule::course_allowed($this->course(1000000000)));
        $this->assertFalse(testable_license_rule::has_any_access());
    }

    public function test_test_mode_licenses_the_site_while_testing(): void {
        $this->set_state('', '', ['lic_testmode' => 1]);
        $this->assertTrue(testable_license_rule::is_valid());
        $this->assertTrue(testable_license_rule::course_allowed($this->course(time())));
    }
}
