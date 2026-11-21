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
 * Exposes the protected verifier with a test key.
 */
final class testable_license_v3 extends license {
    /**
     * Verify a token against a supplied fixture key.
     *
     * @param string $token Token under test.
     * @param string $publickey Public verification key.
     * @param string $site Expected site identifier.
     * @return array|false
     */
    public static function verify_token_with_key(string $token, string $publickey, string $site) {
        return parent::verify_token($token, $publickey, $site);
    }
}
