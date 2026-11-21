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
 * Tests for ACCROS3 tokens issued by the Accros licensing server.
 *
 * Fixtures are signed with a test-only key pair; only its public half is stored.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/testable_license_v3.php');

use PHPUnit\Framework\Attributes\Group;

/**
 * ACCROS3 token verification and site normalisation.
 */
#[Group('block_scormreport')]
final class license_v3_test extends \advanced_testcase {
    /** Site used in the fixtures (PHPUnit wwwroot is https://www.example.com/moodle). */
    private const SITE = 'www.example.com/moodle';

    private function fixture(string $name): string {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        $this->assertNotFalse($contents);
        return trim($contents);
    }

    private function verify(string $token, string $site = self::SITE) {
        return testable_license_v3::verify_token_with_key($token, $this->fixture('test_v3_public.pem'), $site);
    }

    public function test_valid_token_is_accepted(): void {
        $data = $this->verify($this->fixture('v3_valid_token.txt'));
        $this->assertIsArray($data);
        $this->assertSame('annual', $data['type']);
        $this->assertSame(4070908799, $data['exp']);
        $this->assertSame(1792800000, $data['start']);
    }

    public function test_trial_token_is_accepted(): void {
        $data = $this->verify($this->fixture('v3_trial_token.txt'));
        $this->assertIsArray($data);
        $this->assertSame('trial', $data['type']);
    }

    public function test_token_for_another_site_is_rejected(): void {
        $this->assertFalse($this->verify($this->fixture('v3_wrong_site_token.txt')));
        $this->assertFalse($this->verify($this->fixture('v3_valid_token.txt'), 'otro.example.org'));
    }

    public function test_token_for_another_product_is_rejected(): void {
        $this->assertFalse($this->verify($this->fixture('v3_wrong_product_token.txt')));
    }

    public function test_expired_token_is_authentic_but_not_valid_later(): void {
        // Authentic (verify_token does not check dates) — validity is decided by effective_expiry().
        $data = $this->verify($this->fixture('v3_expired_token.txt'));
        $this->assertIsArray($data);
        $this->assertLessThan(time(), $data['exp']);
    }

    public function test_tampered_token_is_rejected(): void {
        $token = $this->fixture('v3_valid_token.txt');
        [$prefix, $payload, $signature] = explode('.', $token);
        $json = base64_decode(strtr($payload, '-_', '+/'));
        $forged = rtrim(strtr(base64_encode(str_replace('4070908799', '4102444799', $json)), '+/', '-_'), '=');
        $this->assertFalse($this->verify($prefix . '.' . $forged . '.' . $signature));
        $this->assertFalse($this->verify('ACCROS2.' . $payload . '.' . $signature));
        $this->assertFalse($this->verify('not-a-token'));
    }

    public function test_token_is_rejected_with_another_key(): void {
        $this->assertFalse(testable_license_v3::verify_token_with_key(
            $this->fixture('v3_valid_token.txt'),
            $this->fixture('test_public.pem'),
            self::SITE
        ));
    }

    public function test_site_normalisation_matches_server(): void {
        $this->assertSame('campus.example.com/moodle', license::normalise_site('https://Campus.Example.com/moodle/'));
        $this->assertSame('campus.example.com', license::normalise_site('http://campus.example.com'));
        $this->assertSame('campus.example.com:8080/m', license::normalise_site('https://campus.example.com:8080/m/'));
        $this->assertSame(self::SITE, license::current_site());
    }

    public function test_new_install_is_not_licensed(): void {
        $this->resetAfterTest();
        license::store_dates(0, 0, '');
        license::reset_caches();
        $this->assertFalse(license::is_valid());
        $this->assertSame('', license::get_type());
        $this->assertSame(0, license::licensed_until());
    }

    public function test_stored_dates_alone_do_not_license_the_site(): void {
        $this->resetAfterTest();
        // Editing the stored dates in the database must have no effect without a signed token.
        license::store_dates(time() - DAYSECS, time() + 365 * DAYSECS, 'full');
        license::reset_caches();
        $this->assertFalse(license::is_valid());
        $this->assertFalse(license::course_allowed((object)['id' => 0, 'startdate' => time() - DAYSECS]));
    }
}
