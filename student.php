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
 * Student personal SCORM report.
 * Uses block_scormreport_get_data() from lib_report.php for all calculations.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

// Redirect teachers to the full report
if (has_capability('block/scormreport:viewreports', $context)) {
    $url = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
    redirect($url);
}

require_capability('block/scormreport:viewownreport', $context);

// License check: courses not covered by the license show the locked page.
if (!\block_scormreport\license::course_allowed($course)) {
    block_scormreport_show_locked_page($PAGE, $OUTPUT, $course, $course->id);
}

$userid = $USER->id;

// SCORMs visible to the student (respecting restrictions)
$modinfo = get_fast_modinfo($course, $USER->id);
$visiblescormids = [];
foreach ($modinfo->get_instances_of('scorm') as $cminfo) {
    if ($cminfo->uservisible && !$cminfo->deletioninprogress) {
        $visiblescormids[] = $cminfo->instance;
    }
}

// Get data from lib_report.php
$reportdata  = block_scormreport_get_data($courseid, $userid, 0, $context, $course, $DB, $CFG);
$coursehours = $reportdata['coursehours'];

// Keep only SCORMs visible to the student
$detaildataall = $reportdata['detaildata'];
$detaildata = [];
foreach ($detaildataall as $row) {
    if (in_array($row['scormid'], $visiblescormids)) {
        // Add 'attempted' and 'time' fields used by the HTML view
        $row['attempted'] = ($row['status'] !== 'notattempted');
        $row['time'] = $row['timescorm'];
        // Add totalscos/passedscos if missing
        if (!isset($row['totalscos'])) {
            $row['totalscos'] = $row['multisco'] ? count($row['scos']) : 1;
        }
        if (!isset($row['passedscos'])) {
            $row['passedscos'] = $row['multisco']
                ? count(array_filter($row['scos'], fn($s) => block_scormreport_is_passed($s['status'])))
                : (block_scormreport_is_passed($row['status']) ? 1 : 0);
        }
        $detaildata[] = $row;
    }
}

$totalscorms = 0;
foreach ($detaildata as $row) {
    if ($row['multisco'] && !empty($row['scos'])) {
        $totalscorms += count($row['scos']);
    } else {
        $totalscorms++;
    }
}

// Recalculate summary using only visible SCORMs
$totalseconds = 0;
$scores       = [];
$seen         = 0;
$passed       = 0;
$firstaccessts = [];
$lastaccessts  = [];

foreach ($detaildata as $row) {
    if ($row['attempted']) {
        $totalseconds += $row['seconds'];
        if ($row['score'] !== '-' && $row['score'] !== false) {
            $scoreval = (float)$row['score'];
            // Ignore a 0 from a theory-only SCORM (no interactions); count real test 0.
            if ($scoreval != 0 || !empty($row['has_interactions'])) {
                $scores[] = $scoreval;
            }
        }
        if ($row['multisco'] && !empty($row['scos'])) {
            foreach ($row['scos'] as $sco) {
                if ($sco['status'] !== 'notattempted') {
                    $seen++;
                }
                if (block_scormreport_is_passed($sco['status'])) {
                    $passed++;
                }
            }
        } else {
            $seen++;
            if (block_scormreport_is_passed($row['status'])) {
                $passed++;
            }
        }
    }
}

// Get timestamps of visible SCORMs for first/last access
$firsts = [];
$lasts  = [];
foreach ($detaildata as $row) {
    if (!empty($row['firstaccess_ts'])) {
        $firsts[] = $row['firstaccess_ts'];
    }
    if (!empty($row['lastaccess_ts'])) {
        $lasts[]  = $row['lastaccess_ts'];
    }
}
$globalfirstaccess = !empty($firsts) ? userdate(min($firsts), '%d/%m/%Y %H:%M') : '-';
$globallastaccess  = !empty($lasts) ? userdate(max($lasts), '%d/%m/%Y %H:%M') : '-';

$pctvistos    = $totalscorms > 0 ? round(($seen / $totalscorms) * 100, 1) : 0;
$pctsuperados = $totalscorms > 0 ? round(($passed / $totalscorms) * 100, 1) : 0;

// Percentage of time against total course hours
$pcttotal = '-';
if (!empty($coursehours) && is_numeric(trim($coursehours)) && $totalseconds > 0) {
    $totalcourseseconds = (int)trim($coursehours) * 3600;
    $pcttotal = round(($totalseconds / $totalcourseseconds) * 100, 1) . '%';
}

// Sum of the time % of each topic
$sumpcttotal = 0;
if (!empty($coursehours) && is_numeric(trim($coursehours))) {
    $totalcourseseconds = (int)trim($coursehours) * 3600;
    foreach ($detaildata as $drow) {
        if (!empty($drow['seconds']) && $drow['seconds'] > 0) {
            $sumpcttotal += ($drow['seconds'] / $totalcourseseconds) * 100;
        }
    }
}
$sumpcttotalstr = $sumpcttotal > 0 ? round($sumpcttotal, 1) . '%' : '-';

if (count($scores) === 0) {
    $notamedia = '-';
} else if (count($scores) === 1) {
    $notamedia = $scores[0];
} else {
    $notamedia = round(array_sum($scores) / count($scores), 1);
}

// ─── HTML ─────────────────────────────────────────────────────────────────────
$PAGE->set_url('/blocks/scormreport/student.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('mystudentreport', 'block_scormreport'));
$PAGE->set_heading($course->fullname);

$PAGE->add_body_class('block_scormreport');
echo $OUTPUT->header();

$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
echo html_writer::link(
    $courseurl,
    get_string('back', 'block_scormreport'),
    ['class' => 'btn btn-secondary mb-3']
);

// Get course dates
$startdate = !empty($course->startdate) ? userdate($course->startdate, '%d/%m/%Y') : '-';
$enddate   = !empty($course->enddate) ? userdate($course->enddate, '%d/%m/%Y') : '-';

// ($coursehours is loaded at the top of the file.)

// Dynamic title, same as teacher view
echo '<div class="scorm-report-header mb-4">';
echo '<h2 class="scorm-report-title">' . get_string('reporttitle', 'block_scormreport') . ': ' . htmlspecialchars($course->fullname) . '</h2>';
echo '<div class="scorm-report-meta">';
if ($startdate !== '-' || $enddate !== '-') {
    echo '<span>📅 ' . $startdate . ' — ' . $enddate . '</span>';
}
if ($coursehours) {
    echo ' &nbsp;|&nbsp; <span>⏱ ' . get_string('coursehourslabel', 'block_scormreport', htmlspecialchars($coursehours)) . '</span>';
}
echo '</div></div>';

// Saludo personalizado
echo '<p class="lead mb-4">' .
    get_string('hello', 'block_scormreport') . ' <strong>' . fullname($USER) . '</strong>, ' .
    get_string('hereyourprogress', 'block_scormreport') .
'</p>';

// Summary cards
echo '
<div class="scorm-summary-cards mb-4">
    <div class="summary-card">
        <span class="card-value">' . $totalscorms . '</span>
        <span class="card-label">' . get_string('totalscorms', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $pctvistos . '%</span>
        <span class="card-label">' . get_string('pctvistos', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $pctsuperados . '%</span>
        <span class="card-label">' . get_string('pctsuperados', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . ($notamedia !== '-' ? $notamedia : '-') . '</span>
        <span class="card-label">' . get_string('score', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $pcttotal . '</span>
        <span class="card-label">' . get_string('pcttimecourse', 'block_scormreport') . '</span>
    </div>
</div>';

// Visual progress bar
echo '
<div class="student-progress-section mb-4">
    <div class="d-flex justify-content-between mb-1">
        <span><strong>' . get_string('pctvistos', 'block_scormreport') . '</strong></span>
        <span>' . $pctvistos . '%</span>
    </div>
    <div class="progress mb-3" style="height:18px;">
        <div class="progress-bar bg-primary" style="width:' . $pctvistos . '%"></div>
    </div>
    <div class="d-flex justify-content-between mb-1">
        <span><strong>' . get_string('pctsuperados', 'block_scormreport') . '</strong></span>
        <span>' . $pctsuperados . '%</span>
    </div>
    <div class="progress" style="height:18px;">
        <div class="progress-bar bg-success" style="width:' . $pctsuperados . '%"></div>
    </div>
</div>';

// ── Donut chart ──
$superados  = $passed;
$incompletos = max(0, $seen - $passed);
$noiniciados = max(0, $totalscorms - $seen);

echo '
<div class="scorm-chart-wrap mb-4">
    <div class="scorm-donut-wrap">
        <canvas id="scorm-donut-chart" width="160" height="160" role="img" aria-label="' . s(get_string('passed', 'block_scormreport') . ': ' . $pctsuperados . '%') . '"></canvas>
        <div class="scorm-donut-center">
            <div class="donut-pct">' . $pctsuperados . '%</div>
            <div class="donut-label">' . get_string('passed', 'block_scormreport') . '</div>
        </div>
    </div>
    <div class="scorm-chart-legend">
        <div class="legend-item">
            <div class="legend-dot" style="background:#28a745;"></div>
            <span>' . get_string('passed', 'block_scormreport') . ': <strong>' . $superados . '</strong></span>
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#ffc107;"></div>
            <span>' . get_string('incomplete', 'block_scormreport') . ': <strong>' . $incompletos . '</strong></span>
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#adb5bd;"></div>
            <span>' . get_string('notattempted', 'block_scormreport') . ': <strong>' . $noiniciados . '</strong></span>
        </div>
    </div>
</div>';

// ── Detail table ─────────────────────────────────────────────────────────────
if (empty($detaildata)) {
    echo $OUTPUT->notification(get_string('nodata', 'block_scormreport'), 'info');
} else {
    echo '
    <div class="table-responsive">
    <table class="table table-striped table-hover scorm-report-table scorm-table-full sortable-table" id="student-table">
        <thead><tr>
            <th class="sortable-col" role="button" tabindex="0" data-col="0">' . get_string('scormname', 'block_scormreport')
                . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="1">' . get_string('timetotal', 'block_scormreport')
                . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="2">'
                . get_string('pcttimecourse', 'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="3">' . get_string('score', 'block_scormreport')
                . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="4">' . get_string('status', 'block_scormreport')
                . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="5">'
                . get_string('firstaccess', 'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            <th class="sortable-col" role="button" tabindex="0" data-col="6">' . get_string('lastaccess', 'block_scormreport')
                . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
        </tr></thead>
        <tbody>
        <tr class="table-primary font-weight-bold">
            <td><strong>' . get_string('allscorms', 'block_scormreport') . '</strong></td>
            <td class="text-center font-monospace"><strong>' . block_scormreport_hms($totalseconds) . '</strong></td>
            <td class="text-center"><strong>' . $sumpcttotalstr . '</strong></td>
            <td class="text-center"><strong>' . ($notamedia !== '-' ? $notamedia : '-') . '</strong></td>
            <td class="text-center"></td>
            <td class="text-center">' . $globalfirstaccess . '</td>
            <td class="text-center">' . $globallastaccess  . '</td>
        </tr>';

    $srowindex = 0;
    foreach ($detaildata as $row) {
        $srowindex++;
        $badgeinfo = block_scormreport_get_badge_info($row['status']);
        $badge = $badgeinfo['badge'];
        $label = $badgeinfo['label'];

        $namecell = htmlspecialchars($row['scormname']);
        if ($row['multisco']) {
            $namecell .= ' <span class="badge badge-info ms-1 badge-multisco">' . get_string('multisco', 'block_scormreport') . '</span>';
            $namecell .= ' <button class="btn btn-xs btn-outline-info ms-1 sco-toggle-s btn-toggle"
                data-target="ssco-detail-' . $srowindex . '"
                >' . get_string('viewtopics', 'block_scormreport') . '</button>';
        }

        $srowclass = '';
        switch (strtolower($row['status'])) {
            case 'passed':
            case 'completed':
                $srowclass = 'row-passed';
                break;
            case 'failed':
                $srowclass = 'row-failed';
                break;
            case 'incomplete':
                $srowclass = 'row-incomplete';
                break;
            default:
                $srowclass = 'row-notattempted';
                break;
        }
        echo '<tr class="' . $srowclass . '">
            <td>' . $namecell . '</td>
            <td class="text-center font-monospace" data-sort="' . ($row['seconds'] ?? 0) . '">' . $row['time']  . '</td>
            <td class="text-center">'
            . (!empty($coursehours) && is_numeric(trim($coursehours)) && $row['seconds'] > 0
                ? round(($row['seconds'] / ((int)trim($coursehours) * 3600)) * 100, 1) . '%'
                : '-') . '
            </td>
            <td class="text-center"><strong>'        . $row['score'] . '</strong></td>
            <td class="text-center">
                <span class="badge ' . $badge . '">' . $label . '</span>
                ' . ($row['multisco'] ? '<br><small class="text-muted-sub">'
                    . get_string('topicslabel', 'block_scormreport', (object)['passed' => $row['passedscos'], 'total' => $row['totalscos']]) . '</small>' : '') . '
            </td>
            <td data-sort="' . ($row['firstaccess_ts'] ?? 0) . '">' . $row['firstaccess'] . '</td>
            <td data-sort="' . ($row['lastaccess_ts'] ?? 0) . '">' . $row['lastaccess']  . '</td>
        </tr>';

        // Sub-table of inner SCOs
        if ($row['multisco'] && !empty($row['scos'])) {
            echo '<tr id="ssco-detail-' . $srowindex . '" class="sco-detail-row" style="display:none;">
                <td colspan="7" class="sco-detail-cell">
                <table class="table table-sm mb-0 sco-inner-table">
                    <thead class="sco-header-row"><tr>
                        <th class="sco-cell-name">📎 ' . get_string('scormname', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('timescorm', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('score', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('status', 'block_scormreport') . '</th>
                        <th class="sco-cell">' . get_string('firstaccess', 'block_scormreport') . '</th>
                        <th class="sco-cell">' . get_string('lastaccess', 'block_scormreport') . '</th>
                    </tr></thead>
                    <tbody>';

            foreach ($row['scos'] as $sco) {
                $scobadge = block_scormreport_get_badge_info($sco['status']);
                $sb = $scobadge['badge'];
                $sl = $scobadge['label'];
                echo '<tr>
                    <td class="sco-cell-name">' . htmlspecialchars($sco['title']) . '</td>
                    <td class="sco-cell-mono">' . block_scormreport_hms($sco['seconds']) . '</td>
                    <td style="text-align:center;">' . (($sco['score'] !== false) ? $sco['score'] : '-') . '</td>
                    <td style="text-align:center;"><span class="badge ' . $sb . '">' . $sl . '</span></td>
                    <td>' . $sco['firstaccess'] . '</td>
                    <td>' . $sco['lastaccess']  . '</td>
                </tr>';
            }
            echo '</tbody></table></td></tr>';
        }
    }

    echo '</tbody></table></div>';
}



$PAGE->requires->js_call_amd('block_scormreport/student', 'init', [[
    'strViewtopics' => get_string('viewtopics', 'block_scormreport'),
    'strHidetopics' => get_string('hidetopics', 'block_scormreport'),
    'passed'        => (int)$superados,
    'incomplete'    => (int)$incompletos,
    'notattempted'  => (int)$noiniciados,
]]);

echo $OUTPUT->footer();
