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

/**
 * Tutor activity: tutors of the course (teachers with or without editing rights)
 * with their course access dates, active days, sessions and daily connection PDF.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context  = context_course::instance($courseid);

require_login($course);
require_capability('block/scormreport:viewreports', $context);

if (!\block_scormreport\license::course_allowed($course)) {
    block_scormreport_show_locked_page($PAGE, $OUTPUT, $course, $courseid);
    // block_scormreport_show_locked_page() calls exit.
}

$canexport = has_capability('block/scormreport:export', $context);

$PAGE->set_url('/blocks/scormreport/tutors.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('tutoractivity', 'block_scormreport'));
$PAGE->set_heading($course->fullname . ' — ' . get_string('tutoractivity', 'block_scormreport'));
$PAGE->add_body_class('block_scormreport');

$tutors = block_scormreport_get_course_tutors($context);
// Connections per day (a Moodle login or 30 minutes without activity starts a new one).
$activity = $tutors ? block_scormreport_get_tutor_daily_activity($courseid, array_keys($tutors)) : [];

echo $OUTPUT->header();

$reporturl = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
echo html_writer::link($reporturl, get_string('backtoreport', 'block_scormreport'),
    ['class' => 'btn btn-secondary mb-3']);

echo $OUTPUT->heading(get_string('tutoractivity', 'block_scormreport'), 3);
echo html_writer::tag('p', get_string('tutoractivity_desc', 'block_scormreport'), ['class' => 'text-muted']);

if (empty($tutors)) {
    echo $OUTPUT->notification(get_string('notutors', 'block_scormreport'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$fmt = function(int $ts): string {
    return $ts ? userdate($ts, '%d/%m/%Y %H:%M') : '-';
};

$pdficon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
    . '<rect width="24" height="24" rx="4" fill="#c0392b"/>'
    . '<text x="12" y="16" font-size="9" font-weight="bold" fill="white" text-anchor="middle" font-family="Arial">PDF</text>'
    . '</svg>';

$headers = [
    get_string('tutor', 'block_scormreport'),
    get_string('tutorrole', 'block_scormreport'),
    get_string('email', 'block_scormreport'),
    get_string('firstaccess', 'block_scormreport'),
    get_string('lastaccess', 'block_scormreport'),
    get_string('activedays', 'block_scormreport'),
    get_string('tutorconnections', 'block_scormreport'),
];
if ($canexport) {
    $headers[] = '';
}

echo '<div class="table-responsive"><table class="generaltable scormreport-table"><thead><tr>';
foreach ($headers as $h) {
    echo html_writer::tag('th', $h);
}
echo '</tr></thead><tbody>';

foreach ($tutors as $tutor) {
    $act = $activity[$tutor->id] ?? ['connections' => 0, 'first' => 0, 'last' => 0, 'days' => []];
    echo '<tr>';
    echo html_writer::tag('td', html_writer::tag('strong', s(fullname($tutor))));
    echo html_writer::tag('td', s($tutor->rolenames));
    echo html_writer::tag('td', s($tutor->email));
    echo html_writer::tag('td', $fmt((int)$act['first']));
    echo html_writer::tag('td', $fmt((int)$act['last']));
    echo html_writer::tag('td', count($act['days']), ['style' => 'text-align:center;']);
    echo html_writer::tag('td', (int)$act['connections'], ['style' => 'text-align:center;']);
    if ($canexport) {
        $connurl = new moodle_url('/blocks/scormreport/generate_connections_pdf.php',
            ['courseid' => $courseid, 'userid' => $tutor->id]);
        $button = html_writer::link($connurl,
            '<span style="display:inline-flex;align-items:center;gap:8px;">' . $pdficon . ' '
                . get_string('printconnections', 'block_scormreport') . '</span>',
            ['class' => 'btn btn-sm', 'target' => '_blank',
             'style' => 'background:#c0392b;border-color:#c0392b;color:#fff;font-weight:600;white-space:nowrap;']);
        echo html_writer::tag('td', $button);
    }
    echo '</tr>';

    // Activity per day.
    if (!empty($act['days'])) {
        echo '<tr><td></td><td colspan="' . (count($headers) - 1) . '" class="sco-subsco-cell">';
        echo '<table class="table table-sm mb-2 sco-inner-table"><thead class="answers-header"><tr>';
        foreach (['tutorday', 'tutordayconnections', 'tutorfirstconnection', 'tutorlastconnection'] as $key) {
            echo html_writer::tag('th', get_string($key, 'block_scormreport'));
        }
        echo '</tr></thead><tbody>';
        foreach ($act['days'] as $day) {
            echo '<tr>';
            echo html_writer::tag('td', userdate($day['date'], '%d/%m/%Y'));
            echo html_writer::tag('td', (int)$day['connections'], ['style' => 'text-align:center;']);
            echo html_writer::tag('td', userdate($day['first'], '%H:%M'));
            echo html_writer::tag('td', userdate($day['last'], '%H:%M'));
            echo '</tr>';
        }
        echo '</tbody></table></td></tr>';
    }
}
echo '</tbody></table></div>';
echo $OUTPUT->footer();
