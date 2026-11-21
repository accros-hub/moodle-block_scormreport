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
 * Add the scormreport block to all existing courses (opt-in, admin only).
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

$task = new \block_scormreport\task\add_block_to_courses();
$task->set_custom_data((object)['offset' => 0]);
\core\task\manager::queue_adhoc_task($task);

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingscormreport']);
redirect(
    $returnurl,
    get_string('addtoall_queued', 'block_scormreport'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
