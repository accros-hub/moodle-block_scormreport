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
 * Privacy provider for block_scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_course;

/**
 * Describes and manages personal data stored by the SCORM Report block.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Describe the personal data stored by this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        // Own table storing reminder notifications.
        $collection->add_database_table('block_scormreport_notif', [
            'userid'      => 'privacy:metadata:notif:userid',
            'courseid'    => 'privacy:metadata:notif:courseid',
            'channel'     => 'privacy:metadata:notif:channel',
            'reason'      => 'privacy:metadata:notif:reason',
            'progress'    => 'privacy:metadata:notif:progress',
            'threshold'   => 'privacy:metadata:notif:threshold',
            'status'      => 'privacy:metadata:notif:status',
            'senderid'    => 'privacy:metadata:notif:senderid',
            'timecreated' => 'privacy:metadata:notif:timecreated',
        ], 'privacy:metadata:notif');

        // Report columns chosen by each user.
        $collection->add_user_preference('block_scormreport_columns', 'privacy:metadata:preference:columns');

        // Core subsystems used.
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');

        // Licensing server: only site data and, when requesting a trial, the administrator's email.
        $collection->add_external_location_link('accros_licensing', [
            'email' => 'privacy:metadata:licensing:email',
            'siteurl' => 'privacy:metadata:licensing:siteurl',
            'versions' => 'privacy:metadata:licensing:versions',
        ], 'privacy:metadata:licensing');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Course contexts where the user was a recipient or sender of a reminder.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {block_scormreport_notif} n
                  JOIN {context} ctx ON ctx.instanceid = n.courseid AND ctx.contextlevel = :courselevel
                 WHERE n.userid = :userid OR n.senderid = :senderid";
        $params = [
            'courselevel' => CONTEXT_COURSE,
            'userid'      => $userid,
            'senderid'    => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }

        $sql = "SELECT userid FROM {block_scormreport_notif} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);

        $sql = "SELECT senderid FROM {block_scormreport_notif} WHERE courseid = :courseid";
        $userlist->add_from_sql('senderid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified user in the specified contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }

            $records = $DB->get_records_select(
                'block_scormreport_notif',
                'courseid = :courseid AND (userid = :userid OR senderid = :senderid)',
                ['courseid' => $context->instanceid, 'userid' => $userid, 'senderid' => $userid]
            );

            if (empty($records)) {
                continue;
            }

            $data = [];
            foreach ($records as $r) {
                $data[] = [
                    'channel'     => $r->channel,
                    'reason'      => $r->reason,
                    'progress'    => $r->progress,
                    'threshold'   => $r->threshold,
                    'status'      => $r->status,
                    'role'        => ($r->userid == $userid) ? 'recipient' : 'sender',
                    'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'block_scormreport'), get_string('connectionlog', 'block_scormreport')],
                (object)['reminders' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_course) {
            return;
        }

        $DB->delete_records('block_scormreport_notif', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user in the specified contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_course) {
                continue;
            }
            // Delete records where user is recipient. Sender records are kept
            // as audit trail but anonymised.
            $DB->delete_records('block_scormreport_notif', [
                'courseid' => $context->instanceid,
                'userid'   => $userid,
            ]);
            $DB->set_field('block_scormreport_notif', 'senderid', 0, [
                'courseid' => $context->instanceid,
                'senderid' => $userid,
            ]);
        }
    }

    /**
     * Delete multiple users within a single context.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $context->instanceid;

        $DB->delete_records_select(
            'block_scormreport_notif',
            "courseid = :courseid AND userid $insql",
            $params
        );

        [$insql2, $params2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params2['courseid'] = $context->instanceid;
        $DB->set_field_select(
            'block_scormreport_notif',
            'senderid',
            0,
            "courseid = :courseid AND senderid $insql2",
            $params2
        );
    }

    /**
     * Export the user preferences stored by this plugin.
     *
     * @param int $userid The user whose preferences are exported.
     */
    public static function export_user_preferences(int $userid) {
        $columns = get_user_preferences('block_scormreport_columns', null, $userid);
        if ($columns !== null) {
            writer::export_user_preference(
                'block_scormreport',
                'block_scormreport_columns',
                $columns,
                get_string('privacy:metadata:preference:columns', 'block_scormreport')
            );
        }
    }
}
