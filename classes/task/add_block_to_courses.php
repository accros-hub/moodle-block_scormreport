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
 * Adhoc task to add the scormreport block to all courses in batches.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport\task;

defined('MOODLE_INTERNAL') || die();

class add_block_to_courses extends \core\task\adhoc_task {

    /** @var int Courses per batch */
    const BATCH_SIZE = 200;

    /**
     * Execute the task: add the block to courses that don't have it yet.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $offset = $data->offset ?? 0;

        $courses = $DB->get_records_select(
            'course',
            'id > 1',
            null,
            'id ASC',
            'id',
            $offset,
            self::BATCH_SIZE
        );

        if (empty($courses)) {
            mtrace("block_scormreport: no more courses to process.");
            return;
        }

        $added = 0;
        foreach ($courses as $c) {
            if (\block_scormreport\helper::add_block_to_course($c->id)) {
                $added++;
            }
        }

        mtrace("block_scormreport: processed " . count($courses) . " courses (added block to {$added}).");

        // If more courses remain, queue another iteration
        if (count($courses) >= self::BATCH_SIZE) {
            $task = new self();
            $task->set_custom_data((object)['offset' => $offset + self::BATCH_SIZE]);
            \core\task\manager::queue_adhoc_task($task);
            mtrace("block_scormreport: queued next batch from offset " . ($offset + self::BATCH_SIZE));
        }
    }
}
