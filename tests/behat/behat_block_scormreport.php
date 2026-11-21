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
 * Browser steps for MMRT-293.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Mink\Exception\ExpectationException;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use block_scormreport\helper;

/**
 * Plugin-specific setup and assertions for the report browser tests.
 */
class behat_block_scormreport extends behat_base {
    /**
     * Enable the test-only license and add the reviewed block.
     *
     * @param string $shortname Course shortname.
     */
    #[Given('the SCORM report test fixture is enabled for :shortname')]
    public function enable_fixture(string $shortname): void {
        global $DB;
        set_config('lic_testmode', 1, 'block_scormreport');
        set_config('autoreminder_enabled', 0, 'block_scormreport');
        helper::add_block_to_course((int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST));
    }

    /**
     * Open the reminder without posting to its send action.
     *
     * @param string $shortname Course shortname.
     */
    #[When('I open the SCORM report reminder form for :shortname')]
    public function open_reminder_form(string $shortname): void {
        global $DB;
        $id = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $this->execute('behat_general::i_visit', ['/blocks/scormreport/send_reminder.php?courseid=' . $id]);
    }

    /**
     * Use the logged-in browser session to fetch each real PDF.
     *
     * @param string $shortname Course shortname.
     * @param string $username Learner username.
     */
    #[Then('the SCORM report downloads all three PDFs for :shortname and :username')]
    public function download_pdfs(string $shortname, string $username): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $session = $this->getSession()->getCookie('MoodleSession');
        if (!$session) {
            throw new ExpectationException('The browser has no Moodle session.', $this->getSession());
        }
        foreach (['generate_report_pdf.php', 'generate_evidence_pdf.php', 'generate_connections_pdf.php'] as $endpoint) {
            $url = $this->locate_path('/blocks/scormreport/' . $endpoint . '?courseid=' . $courseid . '&userid=' . $userid);
            $pdf = download_file_content($url, ['Cookie' => 'MoodleSession=' . $session]);
            if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-') || !str_contains(substr($pdf, -128), '%%EOF')) {
                throw new ExpectationException($endpoint . ' did not return a complete PDF.', $this->getSession());
            }
        }
    }
}
