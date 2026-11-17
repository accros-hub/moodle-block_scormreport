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
 * Uninstall steps for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_block_scormreport_uninstall() {
    global $DB;

    // Remove block instances
    \block_scormreport\helper::remove_all_block_instances();

    // Remove the custom field only if it was created by this plugin
    $created = get_config('block_scormreport', 'created_hours_field');
    if ($created) {
        $fieldid = get_config('block_scormreport', 'created_hours_fieldid');
        $categoryid = get_config('block_scormreport', 'created_hours_categoryid');

        // Use the custom field API so that field data and caches are cleaned up properly.
        if ($fieldid && $DB->record_exists('customfield_field', ['id' => $fieldid])) {
            $field = \core_customfield\field_controller::create((int)$fieldid);
            \core_customfield\api::delete_field_configuration($field);
        }

        if ($categoryid && $DB->record_exists('customfield_category', ['id' => $categoryid])) {
            // Only delete the category if it has no other fields.
            $otherfields = $DB->count_records('customfield_field', ['categoryid' => $categoryid]);
            if ($otherfields === 0) {
                $category = \core_customfield\category_controller::create((int)$categoryid);
                \core_customfield\api::delete_category($category);
            }
        }
    }

    // Remove the notifications table
    $dbman = $DB->get_manager();
    $table = new xmldb_table('block_scormreport_notif');
    if ($dbman->table_exists($table)) {
        $dbman->drop_table($table);
    }

    return true;
}
