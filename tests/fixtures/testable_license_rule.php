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
 * Helpers for testing the licence verifier with fixture keys.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

use block_scormreport\license;

defined('MOODLE_INTERNAL') || die();

/**
 * License class that verifies tokens with the test-only public key.
 */
final class testable_license_rule extends license {
    /**
     * Test-only public key matching the fixtures.
     *
     * @return string
     */
    protected static function public_key(): string {
        return trim(file_get_contents(__DIR__ . '/test_v3_public.pem'));
    }
}
