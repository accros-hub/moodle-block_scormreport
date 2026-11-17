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
 * Event observer for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_scormreport_observer {

    /**
     * Automatically add the scormreport block to a newly created course,
     * only if the auto_add_new_courses setting is enabled (default off).
     *
     * @param \core\event\course_created $event
     */
    public static function course_created(\core\event\course_created $event) {
        if (get_config('block_scormreport', 'auto_add_new_courses')) {
            \block_scormreport\helper::add_block_to_course($event->courseid);
        }
    }
}
