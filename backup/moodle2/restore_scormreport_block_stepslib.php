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
 * Restore structure for SCORM Report notifications.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restores the notification history for a course.
 */
class restore_scormreport_block_structure_step extends restore_structure_step {
    /**
     * Define the notification paths used by restore.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('users');

        if ($userinfo) {
            return [
                    new restore_path_element(
                        'scormreport_notification',
                        '/block/notifications/notification'
                    ),
            ];
        }

        return [];
    }

    /**
     * Process the block root element.
     *
     * @param stdClass $data Block data.
     */
    public function process_block($data) {
        // The block instance is restored by Moodle core.
    }

    /**
     * Restore a notification and remap its user references.
     *
     * @param stdClass $data Notification data.
     */
    public function process_scormreport_notification($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        // Prevent duplicate restoration of the same source notification.
        if ($this->get_mappingid('scormreport_notification', $oldid)) {
            return;
        }

        $userid = $this->get_mappingid('user', $data->userid);

        // Do not associate notifications with an unrelated user.
        if (!$userid) {
            return;
        }

        $senderid = 0;
        if (!empty($data->senderid)) {
            $senderid = $this->get_mappingid('user', $data->senderid);
            if (!$senderid) {
                $senderid = 0;
            }
        }

        $record = new stdClass();
        $record->userid = $userid;
        $record->courseid = $this->get_courseid();
        $record->channel = $data->channel;
        $record->reason = $data->reason;
        $record->progress = $data->progress;
        $record->threshold = $data->threshold;
        $record->status = $data->status;
        $record->senderid = $senderid;
        $record->timecreated = $data->timecreated;

        $newid = $DB->insert_record('block_scormreport_notif', $record);

        $this->set_mapping('scormreport_notification', $oldid, $newid);
    }
}
