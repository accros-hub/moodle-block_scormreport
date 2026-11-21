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

namespace block_scormreport\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Hourly task: asks the Accros licensing server for the license state only when it is due.
 *
 *  - With a current token: after the "refresh" date the server puts in the token (one day).
 *    Picks up renewals, suspensions and revocations. If the server cannot be reached, the
 *    current license keeps working until its end date.
 *  - After the license ended: once a day with the last token, so a renewal made in the Accros
 *    panel reaches this Moodle by itself. It stops asking once the installation is released,
 *    cancelled or unknown to the server.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_license extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_refresh_license', 'block_scormreport');
    }

    /**
     * Run the task.
     */
    public function execute() {
        if (!\block_scormreport\license::refresh_due()) {
            mtrace('block_scormreport: license refresh not due yet.');
            return;
        }
        $result = \block_scormreport\license::refresh();
        mtrace('block_scormreport: license refresh result: ' . $result['code']);
    }
}
