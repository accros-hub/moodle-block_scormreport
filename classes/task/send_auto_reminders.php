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
 * Scheduled task that sends automatic progress reminders.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_scormreport\task;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/messagelib.php');

class send_auto_reminders extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_autoreminder', 'block_scormreport');
    }

    public function execute() {
        global $DB, $CFG;

        $enabled = get_config('block_scormreport', 'autoreminder_enabled');
        if (!$enabled) {
            mtrace('block_scormreport: auto-reminders disabled.');
            return;
        }

        $daysraw = get_config('block_scormreport', 'autoreminder_days');
        $days = ($daysraw === false || $daysraw === '') ? 7 : (int)$daysraw;
        $days = max(1, min(365, $days));
        $thraw = get_config('block_scormreport', 'autoreminder_threshold');
        $threshold = ($thraw === false || $thraw === '') ? 75 : (int)$thraw;
        $threshold = max(0, min(100, $threshold));
        $cutoff = time() - ($days * 86400);
        $cooldown = $days * 86400; // Do not repeat before N days

        require_once(__DIR__ . '/../../lib_report.php');
        require_once(__DIR__ . '/../license.php');

        $courses = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname, c.startdate, c.enddate, c.timecreated
               FROM {course} c
               JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
               JOIN {block_instances} bi ON bi.parentcontextid = ctx.id AND bi.blockname = 'scormreport'
              WHERE c.id > 1 AND c.visible = 1"
        );

        if (empty($courses)) {
            mtrace('block_scormreport: no courses found.');
            return;
        }

        $admin = get_admin();
        $totalSent = 0;
        $str = function($key, $a = null) {
            return get_string($key, 'block_scormreport', $a);
        };

        foreach ($courses as $course) {
            if ($course->enddate && $course->enddate < time()) continue;
            if ($course->startdate && $course->startdate > time()) continue;
            // Same license rule as the report: no reminders for locked courses.
            if (!\block_scormreport\license::course_allowed($course)) {
                mtrace("  Course {$course->id}: not covered by the license, skipped.");
                continue;
            }

            $context = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$context) continue;

            try {
                $reportdata = scormreport_get_data($course->id, 0, 0, $context, $course, $DB, $CFG);
            } catch (\Exception $e) {
                mtrace("  Error in course {$course->id}: " . $e->getMessage());
                continue;
            }

            if (empty($reportdata['globaldata'])) continue;

            foreach ($reportdata['globaldata'] as $row) {
                $pct = (float)rtrim($row['pctsuperados'], '%');
                if ($pct >= $threshold) continue;

                $lastaccess = $row['lastaccess_ts'] ?? 0;
                if ($lastaccess > $cutoff) continue;

                // Active enrolments only for automatic reminders
                $touser = $DB->get_record('user', ['id' => $row['userid'], 'deleted' => 0, 'suspended' => 0]);
                if (!$touser) continue;
                if (!is_enrolled($context, $touser, '', true)) continue;

                // Anti-spam: check last reminder sent
                $lastnotif = $DB->get_field_sql(
                    "SELECT MAX(timecreated) FROM {block_scormreport_notif}
                      WHERE userid = :userid AND courseid = :courseid AND channel = 'auto' AND status = 'sent'",
                    ['userid' => $touser->id, 'courseid' => $course->id]
                );
                if ($lastnotif && (time() - $lastnotif) < $cooldown) {
                    continue; // Already sent less than N days ago
                }

                $subject = $str('autoreminder_subject', $course->fullname);
                $body = $str('remindergreeting', fullname($touser)) . "\n\n"
                    . $str('autoreminder_body', (object)[
                        'course' => $course->fullname,
                        'pct' => $pct,
                        'threshold' => $threshold,
                    ]) . "\n\n---\n"
                    . $str('remindercourse', $course->fullname) . "\n"
                    . $str('reminderprogress', $row['pctsuperados']) . "\n"
                    . $str('reminderdate', userdate(time(), '%d/%m/%Y'));

                $eventdata = new \core\message\message();
                $eventdata->component         = 'block_scormreport';
                $eventdata->name              = 'progressreminder';
                $eventdata->userfrom          = $admin;
                $eventdata->userto            = $touser;
                $eventdata->subject           = $subject;
                $eventdata->fullmessage       = strip_tags($body);
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml   = nl2br(htmlspecialchars($body));
                $eventdata->smallmessage      = $subject;
                $eventdata->notification      = 1;
                $eventdata->courseid          = $course->id;
                $eventdata->contexturl        = $CFG->wwwroot . '/course/view.php?id=' . $course->id;
                $eventdata->contexturlname    = $course->fullname;

                $status = 'failed';
                if (message_send($eventdata)) {
                    $totalSent++;
                    $status = 'sent';
                }

                // Record in the audit log
                $DB->insert_record('block_scormreport_notif', (object)[
                    'userid'      => $touser->id,
                    'courseid'    => $course->id,
                    'channel'     => 'auto',
                    'reason'      => "progress {$pct}% < {$threshold}%, inactive {$days}d",
                    'progress'    => $pct,
                    'threshold'   => $threshold,
                    'status'      => $status,
                    'senderid'    => $admin->id,
                    'timecreated' => time(),
                ]);
            }
        }

        mtrace("block_scormreport: sent $totalSent auto-reminders.");
    }
}
