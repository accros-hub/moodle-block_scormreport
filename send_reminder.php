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
$default_channel = get_config('block_scormreport', 'default_channel') ?: 'internal';
$channel  = optional_param('channel', $default_channel, PARAM_ALPHA);

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
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');
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

$reportdata = scormreport_get_data($courseid, 0, 0, $context, $course, $DB, $CFG);
$globaldata = $reportdata['globaldata'];

// Only filter if a percentage was selected
$inactive = [];
$minpct_selected = ($minpct > 0);
if ($minpct_selected) {
    $inactive = array_filter($globaldata, function($row) use ($minpct) {
        return (float)rtrim($row['pctsuperados'], '%') < $minpct;
    });
}

// ─── Check if SMTP is configured ───

// ─── Defaults ────────────────────────────────────────────────────────────────
$default_subject = $str('reminderdefaultsubject', $course->fullname);
$default_message = $str('reminderdefaultmessage');

$preview_name   = '-';
$preview_vistos = '-';
if (!empty($inactive)) {
    $first = reset($inactive);
    $preview_name   = $first['studentname'];
    $preview_vistos = $first['pctsuperados'];
}

// ─── Send ────────────────────────────────────────────────────────────────────
$sent_email    = 0;
$sent_internal = 0;
$errors        = 0;
$sent_names    = [];

if ($action === 'send') {
    require_sesskey();

    if (!empty($subject) && !empty($message) && !empty($inactive)) {
    // Filter by checkbox-selected students
    $selected_users = optional_param_array('selected_users', [], PARAM_INT);
    if (empty($selected_users)) {
        // No recipients selected — send nothing
        $redirecturl = new moodle_url('/blocks/scormreport/send_reminder.php', [
            'courseid' => $courseid, 'minpct' => $minpct,
        ]);
        \core\notification::warning(get_string('norecipients', 'block_scormreport'));
        redirect($redirecturl);
    }
    $inactive = array_filter($inactive, function($row) use ($selected_users) {
        return in_array((int)$row['userid'], $selected_users, true);
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
        $pctvistos_u   = $row['pctsuperados'];

        $personalmsg = $str('remindergreeting', $alumnoname) . "\n\n"
            . $message . "\n\n"
            . "---\n"
            . $str('remindercourse', $cursofullname) . "\n"
            . $str('reminderprogress', $pctvistos_u) . "\n"
            . $str('reminderdate', $fechahoy) . "\n";

        $personalsubj = $subject . " — " . $cursofullname;

        $ok = false;
        $pct_val = (float)rtrim($row['pctsuperados'] ?? '0', '%');
        $now = time();

        if ($channel === 'email' || $channel === 'both') {
            $result = email_to_user(
                $touser, $from, $personalsubj,
                strip_tags($personalmsg),
                nl2br(htmlspecialchars($personalmsg))
            );
            if ($result) { $sent_email++; $ok = true; }
            else $errors++;

            // Log email channel independently
            $DB->insert_record('block_scormreport_notif', (object)[
                'userid'      => $touser->id,
                'courseid'    => $courseid,
                'channel'     => 'email',
                'reason'      => "manual reminder, minpct={$minpct}",
                'progress'    => $pct_val,
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
            if ($result) { $sent_internal++; $ok = true; }
            else $errors++;

            // Log internal channel independently
            $DB->insert_record('block_scormreport_notif', (object)[
                'userid'      => $touser->id,
                'courseid'    => $courseid,
                'channel'     => 'internal',
                'reason'      => "manual reminder, minpct={$minpct}",
                'progress'    => $pct_val,
                'threshold'   => $minpct,
                'status'      => $result ? 'sent' : 'failed',
                'senderid'    => $USER->id,
                'timecreated' => $now,
            ]);
        }

        if ($ok) {
            $sent_names[] = fullname($touser);
        }
    }
    } // end if

    // PRG: store notifications in session and redirect to avoid double send
    $redirecturl = new moodle_url('/blocks/scormreport/send_reminder.php', [
        'courseid' => $courseid,
        'minpct'   => $minpct,
    ]);

    if ($sent_email > 0) {
        $a = new stdClass();
        $a->count = $sent_email;
        $a->names = implode(', ', array_map('htmlspecialchars', array_unique($sent_names)));
        \core\notification::success($str('emailsentto', $a));
    }
    if ($sent_internal > 0) {
        \core\notification::success($str('internalsentto', $sent_internal));
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

echo '<div class="scorm-report-header mb-4">';
echo '<h2 class="scorm-report-title">✉ ' . $str('sendreminder') . '</h2>';
echo '<div class="scorm-report-meta">' . htmlspecialchars($course->fullname) . '</div>';
echo '</div>';

// ─── Form ────────────────────────────────────────────────────────────────────
echo '<form method="post" action="">';
echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="action" id="form-action" value="preview">';

// STEP 1
echo '<div class="card mb-4">';
echo '<div class="card-header scormreport-card-header">1. ' . $str('definerecipients') . '</div>';
echo '<div class="card-body">';
echo '<label class="font-weight-bold">' . $str('sendtobelow') . '
    <select name="minpct" class="form-control d-inline-block" style="width:100px;" onchange="this.form.submit()" required>';
echo '<option value=""' . ($minpct == 0 ? ' selected' : '') . '>--</option>';
foreach ([25, 50, 75, 100] as $pct) {
    $sel = ($minpct == $pct) ? ' selected' : '';
    echo '<option value="' . $pct . '"' . $sel . '>' . $pct . '%</option>';
}
echo '</select> ' . $str('oftopicspassed') . '</label>';
echo '</div></div>';

// STEP 2
echo '<div class="card mb-4">';
echo '<div class="card-header scormreport-card-header">2. ' . $str('recipientlist', count($inactive)) . '</div>';
echo '<div class="card-body p-0">';
if (!$minpct_selected) {
    echo '<p class="p-3 text-muted">' . $str('selectpercentfirst') . '</p>';
} else if (empty($inactive)) {
    echo '<p class="p-3 text-muted">' . $str('norecipientsbelow', $minpct) . '</p>';
} else {
    echo '<table class="table table-sm mb-0 scorm-report-table">
        <thead><tr>
            <th style="width:40px;text-align:center;">
                <input type="checkbox" id="select-all" checked title="' . $str('filterall') . '"
                   >
            </th>
            <th>' . $str('student') . '</th>
            <th>' . $str('email') . '</th>
            <th class="text-center">' . $str('pctsuperados') . '</th>
            <th class="text-center">' . $str('pctvistos') . '</th>
            <th>' . $str('lastaccess') . '</th>
        </tr></thead><tbody>';
    foreach ($inactive as $row) {
        echo '<tr>
            <td style="text-align:center;">
                <input type="checkbox" class="recipient-check" name="selected_users[]"
                    value="' . $row['userid'] . '" checked>
            </td>
            <td>' . htmlspecialchars($row['studentname']) . '</td>
            <td><small>' . htmlspecialchars($row['email']) . '</small></td>
            <td class="text-center"><strong>' . $row['pctsuperados'] . '</strong></td>
            <td class="text-center">' . $row['pctvistos'] . '</td>
            <td>' . $row['lastaccess'] . '</td>
        </tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

// STEP 3
echo '<div class="card mb-4">';
echo '<div class="card-header scormreport-card-header">3. ' . $str('sendchannel') . '</div>';
echo '<div class="card-body">';
echo '<div class="d-flex gap-3" style="gap:1.5rem;flex-wrap:wrap;">';

$channels = [
    'email'    => [$str('channelemailonly'),    $str('channelemaildesc')],
    'internal' => [$str('channelinternalonly'), $str('channelinternaldesc')],
    'both'     => [$str('channelboth'),         $str('channelbothdesc')],
];
foreach ($channels as $val => [$label, $desc]) {
    $checked = ($channel === $val) ? ' checked' : '';
    $selclass = ($channel === $val) ? ' scormreport-channel-selected' : '';
    echo '<div style="flex:1;min-width:200px;">
        <label for="ch_' . $val . '" class="card h-100 scormreport-channel-card' . $selclass . '" style="cursor:pointer;display:block;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <input type="radio" name="channel" id="ch_' . $val . '" value="' . $val . '"' . $checked . ' onchange="this.closest(\'form\').submit()" style="margin:0;">
                    <span class="font-weight-bold">' . $label . '</span>
                </div>
                <small class="text-muted">' . $desc . '</small>
            </div>
        </label>
    </div>';
}
echo '</div>';
echo '</div></div>';
echo '<div class="card mb-4">';
echo '<div class="card-header scormreport-card-header">4. ' . $str('composemessage') . '</div>';
echo '<div class="card-body">';

echo '<div class="alert alert-info" style="font-size:0.85rem;background:#EBF5FB;border-color:#0073aa;">';
echo '<strong>' . $str('messageautobuilds') . '</strong><br>';
echo '• ' . $str('messageautogreeting') . '<br>';
echo '• ' . $str('messageautobody') . '<br>';
echo '• ' . $str('messageautofooter') . '<br>';
echo '</div>';

if (!empty($inactive)) {
    echo '<div class="card mb-3" style="border:1px solid #0073aa;">';
    echo '<div class="card-header" style="background:#EBF5FB;font-size:0.85rem;font-weight:600;color:#0073aa;">
        ' . $str('previewfor', htmlspecialchars($preview_name)) . '
    </div>';
    echo '<div class="card-body p-3" style="font-size:0.85rem;">';
    echo '<div id="preview-box" style="white-space:pre-wrap;font-family:Arial,sans-serif;"></div>';
    echo '</div></div>';
}

$cur_subject = htmlspecialchars($subject ?: $default_subject);
$cur_message = htmlspecialchars($message ?: $default_message);

echo '<div class="form-group">
    <label class="font-weight-bold">' . $str('subjectlabel') . '</label>
    <input type="text" name="subject" id="msg-subject" class="form-control" value="' . $cur_subject . '" required>
</div>';

echo '<div class="form-group">
    <label class="font-weight-bold">' . $str('messagelabel') . '</label>
    <textarea name="message" id="msg-body" class="form-control" rows="10" required>' . $cur_message . '</textarea>
</div>';

echo '</div></div>';

// Send button (dynamic count based on checkboxes)
if (!empty($inactive)) {
    $n = count($inactive);
    $sendLabel = $str('sendtocount', '__COUNT__');
    echo '<button type="submit" id="btn-send" class="btn btn-primary btn-lg"
        data-confirm="' . htmlspecialchars($str('confirmsendn', '__COUNT__'), ENT_QUOTES) . '"
        data-norecipients="' . htmlspecialchars($str('norecipients'), ENT_QUOTES) . '">
        ' . str_replace('__COUNT__', '<span id="send-count">' . $n . '</span>', $sendLabel) . '
    </button>';
} else {
    echo '<button type="button" class="btn btn-secondary btn-lg" disabled>' . $str('norecipients') . '</button>';
}

echo '</form>';

// JS preview — build header/footer with the real values in PHP
$preview_header = $str('remindergreeting', $preview_name) . "\n\n";
$preview_footer = "---\n"
    . $str('remindercourse', $course->fullname) . "\n"
    . $str('reminderprogress', $preview_vistos) . "\n"
    . $str('reminderdate', userdate(time(), '%d/%m/%Y'));


$PAGE->requires->js_call_amd('block_scormreport/reminder', 'init', [[
    'previewHeader' => $preview_header,
    'previewFooter' => $preview_footer,
]]);

echo $OUTPUT->footer();
