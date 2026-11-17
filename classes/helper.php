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
 * Helper functions for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport;

defined('MOODLE_INTERNAL') || die();

class helper {

    /**
     * Add the scormreport block to a course if it doesn't already exist.
     *
     * @param int $courseid
     * @return bool true if the block was added, false if it already existed
     */
    public static function add_block_to_course(int $courseid): bool {
        global $DB;

        if ($courseid <= 1) {
            return false;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        // Check if block already exists in this course
        $exists = $DB->record_exists('block_instances', [
            'blockname'       => 'scormreport',
            'parentcontextid' => $context->id,
        ]);

        if ($exists) {
            return false;
        }

        $bi = new \stdClass();
        $bi->blockname        = 'scormreport';
        $bi->parentcontextid  = $context->id;
        $bi->showinsubcontexts = 0;
        $bi->pagetypepattern  = 'course-view-*';
        $bi->subpagepattern   = null;
        $bi->defaultregion    = 'side-pre';
        $bi->defaultweight    = 0;
        $bi->configdata       = '';
        $bi->timecreated      = time();
        $bi->timemodified     = time();
        $DB->insert_record('block_instances', $bi);

        return true;
    }

    /**
     * Remove all scormreport block instances from all courses.
     * Called during plugin uninstall.
     */
    public static function remove_all_block_instances(): void {
        global $DB;

        $instances = $DB->get_records('block_instances', ['blockname' => 'scormreport']);
        foreach ($instances as $bi) {
            blocks_delete_instance($bi);
        }
    }
}
