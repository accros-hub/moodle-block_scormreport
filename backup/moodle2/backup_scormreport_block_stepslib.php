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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Backup structure for SCORM Report notifications.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the notification history backup structure.
 */
class backup_scormreport_block_structure_step extends backup_block_structure_step {

    protected function define_structure() {
        $userinfo = $this->get_setting_value('users');

        $notifications = new backup_nested_element('notifications', array('id'));

        $notification = new backup_nested_element('notification', array('id'), array(
            'userid',
            'channel',
            'reason',
            'progress',
            'threshold',
            'status',
            'senderid',
            'timecreated',
        ));

        $notifications->add_child($notification);

        $notifications->set_source_array(array((object) array('id' => $this->task->get_blockid())));

        if ($userinfo) {
            $notification->set_source_table(
                'block_scormreport_notif',
                array('courseid' => backup::VAR_COURSEID)
            );
        }

        $notification->annotate_ids('user', 'userid');
        $notification->annotate_ids('user', 'senderid');

        return $this->prepare_block_structure($notifications);
    }
}
