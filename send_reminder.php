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
 * Send reminder to inactive students.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/messagelib.php');

$courseid = required_param('courseid', PARAM_INT);
$minpct   = optional_param('minpct',   0,   PARAM_INT);
$action   = optional_param('action',  'preview', PARAM_ALPHA);
$subject  = optional_param('subject',  '', PARAM_TEXT);
$message  = optional_param('message',  '', PARAM_RAW);
$defaultchannel = get_config('block_scormreport', 'default_channel') ?: 'internal';
$channel  = optional_param('channel', $defaultchannel, PARAM_ALPHA);

// Validar valores permitidos
if ($minpct > 0 && !in_array($minpct, [25, 50, 75, 100])) {
    $minpct = 0;
}
if (!in_array($channel, ['email', 'internal', 'both'])) {
    $channel = 'email';
}

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/scormreport:sendreminders', $context);

// License check: only courses covered by the license.
if (!\block_scormreport\license::course_allowed($course)) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

$PAGE->set_url('/blocks/scormreport/send_reminder.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('sendremindertitle', 'block_scormreport', $course->fullname));
$PAGE->set_heading($course->fullname);

$str = function($key, $a = null) { return get_string($key, 'block_scormreport', $a); };

// ─── Data ────────────────────────────────────────────────────────────────────
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');

$reportdata = block_scormreport_get_data($courseid, 0, 0, $context, $course, $DB, $CFG);
$globaldata = $reportdata['globaldata'];

// Only filter if a percentage was selected
$inactive = [];
$minpctselected = ($minpct > 0);
if ($minpctselected) {
    $inactive = array_filter($globaldata, function($row) use ($minpct) {
        return (float)rtrim($row['pctsuperados'], '%') < $minpct;
    });
}

// ─── Check if SMTP is configured ───

// ─── Defaults ────────────────────────────────────────────────────────────────
$defaultsubject = $str('reminderdefaultsubject', $course->fullname);
$defaultmessage = $str('reminderdefaultmessage');

$previewname   = '-';
$previewvistos = '-';
if (!empty($inactive)) {
    $first = reset($inactive);
    $previewname   = $first['studentname'];
    $previewvistos = $first['pctsuperados'];
}

// ─── Send ────────────────────────────────────────────────────────────────────
$sentemail    = 0;
$sentinternal = 0;
$errors        = 0;
$sentnames    = [];

if ($action === 'send') {
    require_sesskey();

    if (!empty($subject) && !empty($message) && !empty($inactive)) {
    // Filter by checkbox-selected students
    $selectedusers = optional_param_array('selected_users', [], PARAM_INT);
    if (empty($selectedusers)) {
        // No recipients selected — send nothing
        $redirecturl = new moodle_url('/blocks/scormreport/send_reminder.php', [
            'courseid' => $courseid, 'minpct' => $minpct,
        ]);
        \core\notification::warning(get_string('norecipients', 'block_scormreport'));
        redirect($redirecturl);
    }
    $inactive = array_filter($inactive, function($row) use ($selectedusers) {
        return in_array((int)$row['userid'], $selectedusers, true);
    });

    $from = $DB->get_record('user', ['id' => $USER->id]);

    foreach ($inactive as $row) {
        $touser = $DB->get_record('user', ['id' => $row['userid'], 'deleted' => 0, 'suspended' => 0]);
        if (!$touser) continue;
        // Only send to actively enrolled students.
        if (!is_enrolled($context, $touser, '', true)) continue;

        $alumnoname    = fullname($touser);
        $cursofullname = $course->fullname;
        $fechahoy      = userdate(time(), '%d/%m/%Y');
        $pctvistosu   = $row['pctsuperados'];

        $personalmsg = $str('remindergreeting', $alumnoname) . "\n\n"
            . $message . "\n\n"
            . "---\n"
            . $str('remindercourse', $cursofullname) . "\n"
            . $str('reminderprogress', $pctvistosu) . "\n"
            . $str('reminderdate', $fechahoy) . "\n";

        $personalsubj = $subject . " — " . $cursofullname;

        $ok = false;
        $pctval = (float)rtrim($row['pctsuperados'] ?? '0', '%');
        $now = time();

        if ($channel === 'email' || $channel === 'both') {
            $result = email_to_user(
                $touser, $from, $personalsubj,
                strip_tags($personalmsg),
                nl2br(htmlspecialchars($personalmsg))
            );
            if ($result) { $sentemail++; $ok = true; }
            else $errors++;

            // Log email channel independently
            $DB->insert_record('block_scormreport_notif', (object)[
                'userid'      => $touser->id,
                'courseid'    => $courseid,
                'channel'     => 'email',
                'reason'      => "manual reminder, minpct={$minpct}",
                'progress'    => $pctval,
                'threshold'   => $minpct,
                'status'      => $result ? 'sent' : 'failed',
                'senderid'    => $USER->id,
                'timecreated' => $now,
            ]);
        }

        if ($channel === 'internal' || $channel === 'both') {
            $eventdata = new \core\message\message();
            $eventdata->component         = 'block_scormreport';
            $eventdata->name              = 'progressreminder';
            $eventdata->userfrom          = $from;
            $eventdata->userto            = $touser;
            $eventdata->subject           = $personalsubj;
            $eventdata->fullmessage       = strip_tags($personalmsg);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = nl2br(htmlspecialchars($personalmsg));
            $eventdata->smallmessage      = $personalsubj;
            $eventdata->notification      = 1;
            $eventdata->courseid          = $courseid;
            $eventdata->contexturl        = $CFG->wwwroot . '/course/view.php?id=' . $courseid;
            $eventdata->contexturlname    = $course->fullname;

            $result = message_send($eventdata);
            if ($result) { $sentinternal++; $ok = true; }
            else $errors++;

            // Log internal channel independently
            $DB->insert_record('block_scormreport_notif', (object)[
                'userid'      => $touser->id,
                'courseid'    => $courseid,
                'channel'     => 'internal',
                'reason'      => "manual reminder, minpct={$minpct}",
                'progress'    => $pctval,
                'threshold'   => $minpct,
                'status'      => $result ? 'sent' : 'failed',
                'senderid'    => $USER->id,
                'timecreated' => $now,
            ]);
        }

        if ($ok) {
            $sentnames[] = fullname($touser);
        }
    }
    } // end if

    // PRG: store notifications in session and redirect to avoid double send
    $redirecturl = new moodle_url('/blocks/scormreport/send_reminder.php', [
        'courseid' => $courseid,
        'minpct'   => $minpct,
    ]);

    if ($sentemail > 0) {
        $a = new stdClass();
        $a->count = $sentemail;
        $a->names = implode(', ', array_map('htmlspecialchars', array_unique($sentnames)));
        \core\notification::success($str('emailsentto', $a));
    }
    if ($sentinternal > 0) {
        \core\notification::success($str('internalsentto', $sentinternal));
    }
    if ($errors > 0) {
        \core\notification::warning($str('senderrors', $errors));
    }

    redirect($redirecturl);
}

// ─── HTML ────────────────────────────────────────────────────────────────────
$PAGE->add_body_class('block_scormreport');
echo $OUTPUT->header();

$reporturl = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
echo html_writer::link($reporturl, $str('backtoreport'), ['class' => 'btn btn-secondary mb-3']);

$percentoptions = [['value' => '', 'label' => '--', 'selected' => $minpct === 0]];
foreach ([25, 50, 75, 100] as $pct) {
    $percentoptions[] = ['value' => $pct, 'label' => $pct . '%', 'selected' => $minpct === $pct];
}
$channeloptions = [];
foreach ([
    'email' => ['channelemailonly', 'channelemaildesc'],
    'internal' => ['channelinternalonly', 'channelinternaldesc'],
    'both' => ['channelboth', 'channelbothdesc'],
] as $value => $keys) {
    $channeloptions[] = [
        'value' => $value,
        'label' => $str($keys[0]),
        'description' => $str($keys[1]),
        'selected' => $channel === $value,
    ];
}
$sendlabel = $str('sendtocount', '__COUNT__');
$sendparts = explode('__COUNT__', $sendlabel, 2);
$recipients = array_values($inactive);
echo $OUTPUT->render_from_template('block_scormreport/reminder_form', [
    'title' => $str('sendreminder'),
    'coursename' => $course->fullname,
    'courseid' => $courseid,
    'sesskey' => sesskey(),
    'recipientsheading' => $str('definerecipients'),
    'sendtobelow' => $str('sendtobelow'),
    'percentoptions' => $percentoptions,
    'oftopicspassed' => $str('oftopicspassed'),
    'recipientlistheading' => $str('recipientlist', count($inactive)),
    'selectpercentfirst' => !$minpctselected,
    'selectpercentfirstlabel' => $str('selectpercentfirst'),
    'norecipients' => $minpctselected && empty($inactive),
    'norecipientslabel' => $str('norecipientsbelow', $minpct),
    'hasrecipients' => !empty($inactive),
    'filterall' => $str('filterall'),
    'studentlabel' => $str('student'),
    'emaillabel' => $str('email'),
    'pctsuperadoslabel' => $str('pctsuperados'),
    'pctvistoslabel' => $str('pctvistos'),
    'lastaccesslabel' => $str('lastaccess'),
    'recipients' => $recipients,
    'channelheading' => $str('sendchannel'),
    'channels' => $channeloptions,
    'composeheading' => $str('composemessage'),
    'messageautobuilds' => $str('messageautobuilds'),
    'messageautogreeting' => $str('messageautogreeting'),
    'messageautobody' => $str('messageautobody'),
    'messageautofooter' => $str('messageautofooter'),
    'previewfor' => $str('previewfor', $previewname),
    'subjectlabel' => $str('subjectlabel'),
    'messagelabel' => $str('messagelabel'),
    'subject' => $subject ?: $defaultsubject,
    'message' => $message ?: $defaultmessage,
    'confirmtext' => $str('confirmsendn', '__COUNT__'),
    'norecipientstext' => $str('norecipients'),
    'sendbefore' => $sendparts[0],
    'sendafter' => $sendparts[1] ?? '',
    'recipientcount' => count($inactive),
]);

// JS preview — build header/footer with the real values in PHP
$previewheader = $str('remindergreeting', $previewname) . "\n\n";
$previewfooter = "---\n"
    . $str('remindercourse', $course->fullname) . "\n"
    . $str('reminderprogress', $previewvistos) . "\n"
    . $str('reminderdate', userdate(time(), '%d/%m/%Y'));


$PAGE->requires->js_call_amd('block_scormreport/reminder', 'init', [[
    'previewHeader' => $previewheader,
    'previewFooter' => $previewfooter,
]]);

echo $OUTPUT->footer();
