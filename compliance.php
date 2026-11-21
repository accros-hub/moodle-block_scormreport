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
 * Training Compliance Dashboard — overview of all courses with scormreport.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib_report.php');

require_login();
$context = context_system::instance();
require_capability('block/scormreport:viewcompliance', $context);

// License check: nothing to show when no course is covered (never licensed, suspended or
// cancelled). Otherwise courses are filtered one by one below.
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');
if (!\block_scormreport\license::has_any_access()) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

// Filter: active | recent (last 60 days) | all. Default: recent.
$filter = optional_param('filter', 'recent', PARAM_ALPHA);
if (!in_array($filter, ['active', 'recent', 'all'])) {
    $filter = 'recent';
}

$str = function($key, $a = null) { return get_string($key, 'block_scormreport', $a); };
$apt_threshold = get_config('block_scormreport', 'apt_threshold');
$apt_threshold = ($apt_threshold === false || $apt_threshold === '') ? 75 : (int)$apt_threshold;
$apt_threshold = max(0, min(100, $apt_threshold));

$PAGE->set_url('/blocks/scormreport/compliance.php', ['filter' => $filter]);
$PAGE->set_context($context);
$PAGE->set_title($str('compliancetitle'));
$PAGE->set_heading($str('compliancetitle'));
$PAGE->set_pagelayout('report');

$PAGE->add_body_class('block_scormreport');
echo $OUTPUT->header();

// Build filter WHERE clause.
$now = time();
$where = 'c.id > 1';
$params = [];
if ($filter === 'active') {
    // Visible courses whose end date is in the future or unset.
    $where .= ' AND c.visible = 1 AND (c.enddate = 0 OR c.enddate > :now)';
    $params['now'] = $now;
} else if ($filter === 'recent') {
    // Courses that ended within the last 60 days, or are still active.
    $cutoff = $now - (60 * 86400);
    $where .= ' AND (c.enddate = 0 OR c.enddate > :cutoff)';
    $params['cutoff'] = $cutoff;
}
// 'all' → no extra filter.

// ── Role-based course visibility ──
// Site admins and users with a "see all" role see every course.
// Users with a "see own" role see only courses where they are enrolled with that role.
$sees_all = is_siteadmin();
$own_course_ids = null; // null = no restriction; array = restrict to these ids.

if (!$sees_all) {
    $rolesall = get_config('block_scormreport', 'dashboard_roles_all');
    $rolesown = get_config('block_scormreport', 'dashboard_roles_own');
    $rolesall = $rolesall ? explode(',', $rolesall) : [];
    $rolesown = $rolesown ? explode(',', $rolesown) : [];

    // Does the user hold any "see all" role anywhere at system level?
    if (!empty($rolesall)) {
        $sysroles = $DB->get_fieldset_select('role_assignments', 'roleid',
            'userid = :uid', ['uid' => $USER->id]);
        foreach ($rolesall as $rid) {
            if (in_array($rid, $sysroles)) { $sees_all = true; break; }
        }
    }

    if (!$sees_all) {
        // Collect courses where the user is enrolled with any "see own" role.
        $own_course_ids = [];
        if (!empty($rolesown)) {
            list($rinsql, $rparams) = $DB->get_in_or_equal($rolesown, SQL_PARAMS_NAMED, 'r');
            $rparams['uid'] = $USER->id;
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT ctx.instanceid AS courseid
                   FROM {role_assignments} ra
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                  WHERE ra.userid = :uid AND ra.roleid $rinsql",
                $rparams
            );
            foreach ($rows as $row) {
                $own_course_ids[] = $row->courseid;
            }
        }
        // If the user has no qualifying role and no own courses, show nothing.
        if (empty($own_course_ids)) {
            $own_course_ids = [-1]; // impossible id → empty result
        }
    }
}

// Apply role restriction to the WHERE clause.
if (!$sees_all && $own_course_ids !== null) {
    list($cinsql, $cparams) = $DB->get_in_or_equal($own_course_ids, SQL_PARAMS_NAMED, 'oc');
    $where .= " AND c.id $cinsql";
    $params = array_merge($params, $cparams);
}

// Get courses with our block, applying the filter.
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate, c.visible, c.timecreated,
            cat.name AS categoryname
       FROM {course} c
       JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
       JOIN {block_instances} bi ON bi.parentcontextid = ctx.id AND bi.blockname = 'scormreport'
       LEFT JOIN {course_categories} cat ON cat.id = c.category
      WHERE $where
      ORDER BY cat.name, c.fullname",
    $params
);

if (empty($courses)) {
    echo $OUTPUT->notification($str('nocoursesfound'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Calculate stats per course
$stats = [];
$totals = ['courses' => 0, 'students' => 0, 'apto' => 0, 'noapto' => 0, 'risk' => 0, 'nodata' => 0];

$lockedcourses = 0;
foreach ($courses as $c) {
    // Courses that started after the license ended are not included.
    if (!\block_scormreport\license::course_allowed($c)) {
        $lockedcourses++;
        continue;
    }
    $cctx = context_course::instance($c->id, IGNORE_MISSING);
    if (!$cctx) continue;

    try {
        $rd = block_scormreport_get_data($c->id, 0, 0, $cctx, $c, $DB, $CFG);
    } catch (\Exception $e) {
        continue;
    }

    $numstudents = count($rd['students']);
    if ($numstudents === 0) continue;

    $apto = 0; $noapto = 0; $risk = 0; $noactivity = 0;
    $deadline_tolerance = get_config('block_scormreport', 'deadline_tolerance');
$deadline_tolerance = ($deadline_tolerance === false || $deadline_tolerance === '') ? 15 : (int)$deadline_tolerance;
$deadline_tolerance = max(0, min(100, $deadline_tolerance));

    foreach ($rd['globaldata'] as $row) {
        $pct = (float)rtrim($row['pctsuperados'], '%');
        if ($pct >= $apt_threshold) {
            $apto++;
        } else {
            $noapto++;
        }
        // Deadline risk
        $dl = block_scormreport_calc_deadline_status($pct, $c->startdate ?? 0, $c->enddate ?? 0, $deadline_tolerance);
        if ($dl['status'] === 'atrisk' || $dl['status'] === 'finished') {
            $risk++;
        }
        // No activity at all
        if ($row['firstaccess_ts'] == 0) {
            $noactivity++;
        }
    }

    $pctapto = $numstudents > 0 ? round(($apto / $numstudents) * 100, 1) : 0;

    $stats[] = [
        'courseid'     => $c->id,
        'coursename'   => $c->fullname,
        'shortname'    => $c->shortname,
        'category'     => $c->categoryname ?? '-',
        'startdate'    => $c->startdate ? userdate($c->startdate, '%d/%m/%Y') : '-',
        'enddate'      => $c->enddate ? userdate($c->enddate, '%d/%m/%Y') : '-',
        'students'     => $numstudents,
        'apto'         => $apto,
        'noapto'       => $noapto,
        'pctapto'      => $pctapto,
        'risk'         => $risk,
        'noactivity'   => $noactivity,
        'totalscorms'  => $rd['totalscorms'],
    ];

    $totals['courses']++;
    $totals['students'] += $numstudents;
    $totals['apto'] += $apto;
    $totals['noapto'] += $noapto;
    $totals['risk'] += $risk;
    $totals['nodata'] += $noactivity;
}

// ── Dashboard Header ──
echo '<div class="scorm-report-table">';

echo '<h3 style="color:#0073aa;margin-bottom:20px;">📊 ' . $str('compliancetitle') . '</h3>';

// Filter selector.
echo '<div style="margin-bottom:20px;">';
echo '<span style="font-weight:600;margin-right:10px;">' . $str('filtershow') . ':</span>';
$filteroptions = [
    'active' => $str('filter_active'),
    'recent' => $str('filter_recent'),
    'all'    => $str('filter_all'),
];
foreach ($filteroptions as $key => $label) {
    $url = new moodle_url('/blocks/scormreport/compliance.php', ['filter' => $key]);
    $active = ($filter === $key);
    echo html_writer::link($url, $label, [
        'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-secondary') . ' me-1',
        'style' => 'font-weight:600;',
    ]);
}
echo '</div>';

// Courses left out because they started after the license ended.
if ($lockedcourses > 0) {
    echo $OUTPUT->notification($str('compliance_locked_courses', $lockedcourses), 'warning', false);
}

// Summary cards
echo '<div style="display:flex;gap:15px;flex-wrap:wrap;margin-bottom:25px;">';

$cards = [
    ['label' => $str('totalcourses'),    'value' => $totals['courses'],  'color' => '#0073aa'],
    ['label' => $str('totalstudents'),   'value' => $totals['students'], 'color' => '#2196F3'],
    ['label' => $str('apt'),             'value' => $totals['apto'],     'color' => '#28a745'],
    ['label' => $str('notapt'),          'value' => $totals['noapto'],   'color' => '#dc3545'],
    ['label' => $str('studentsatrisk'),  'value' => $totals['risk'],     'color' => '#e67e22'],
    ['label' => $str('noactivitylabel'), 'value' => $totals['nodata'],   'color' => '#6c757d'],
];

foreach ($cards as $card) {
    echo '<div style="flex:1;min-width:140px;background:' . $card['color'] . ';color:#fff;border-radius:10px;padding:15px;text-align:center;">
        <div style="font-size:2rem;font-weight:700;">' . $card['value'] . '</div>
        <div style="font-size:0.85rem;opacity:0.9;">' . $card['label'] . '</div>
    </div>';
}
echo '</div>';

// ── Alerts ──
$alertcourses = array_filter($stats, fn($s) => $s['risk'] > 0 || $s['noactivity'] > 2);
if (!empty($alertcourses)) {
    echo '<div class="alert" style="background:#fff3cd;border-color:#ffc107;border-radius:8px;padding:15px;margin-bottom:20px;">';
    echo '<strong>⚠ ' . $str('compliancealerts') . ':</strong><ul style="margin:8px 0 0 0;">';
    foreach ($alertcourses as $ac) {
        $alerts = [];
        if ($ac['risk'] > 0) $alerts[] = $ac['risk'] . ' ' . strtolower($str('studentsatrisk'));
        if ($ac['noactivity'] > 0) $alerts[] = $ac['noactivity'] . ' ' . strtolower($str('noactivitylabel'));
        echo '<li><strong>' . htmlspecialchars($ac['coursename']) . '</strong>: ' . implode(', ', $alerts) . '</li>';
    }
    echo '</ul></div>';
}

// ── Table ──
echo '<table class="table table-striped table-hover scorm-report-table sortable-table">';
echo '<thead><tr style="background:#0073aa;color:#fff;">
    <th>' . $str('coursename_label') . '</th>
    <th class="text-center">' . $str('coursedates_label') . '</th>
    <th class="text-center">' . $str('totalstudents') . '</th>
    <th class="text-center">' . $str('apt') . '</th>
    <th class="text-center">' . $str('notapt') . '</th>
    <th class="text-center">% ' . $str('apt') . '</th>
    <th class="text-center">' . $str('studentsatrisk') . '</th>
    <th class="text-center">' . $str('noactivitylabel') . '</th>
    <th class="text-center">' . $str('actions') . '</th>
</tr></thead><tbody>';

foreach ($stats as $s) {
    // Color code the % apto cell
    $pctclass = $s['pctapto'] >= 80 ? '#28a745' : ($s['pctapto'] >= 50 ? '#ffc107' : '#dc3545');
    $riskbadge = $s['risk'] > 0
        ? '<span class="badge badge-danger">' . $s['risk'] . '</span>'
        : '<span class="badge badge-success">0</span>';
    $nobadge = $s['noactivity'] > 0
        ? '<span class="badge badge-warning">' . $s['noactivity'] . '</span>'
        : '<span class="badge badge-success">0</span>';

    $reporturl = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $s['courseid']]);

    echo '<tr>
        <td><a href="' . $reporturl->out(false) . '">' . htmlspecialchars($s['coursename']) . '</a><br>
            <small class="text-muted">' . htmlspecialchars($s['category']) . '</small></td>
        <td class="text-center"><small>' . $s['startdate'] . '<br>' . $s['enddate'] . '</small></td>
        <td class="text-center"><strong>' . $s['students'] . '</strong></td>
        <td class="text-center"><span style="color:#28a745;font-weight:600;">' . $s['apto'] . '</span></td>
        <td class="text-center"><span style="color:#dc3545;font-weight:600;">' . $s['noapto'] . '</span></td>
        <td class="text-center"><span style="background:' . $pctclass . ';color:#fff;padding:2px 8px;border-radius:10px;font-weight:600;">' . $s['pctapto'] . '%</span></td>
        <td class="text-center">' . $riskbadge . '</td>
        <td class="text-center">' . $nobadge . '</td>
        <td class="text-center">
            <a href="' . $reporturl->out(false) . '" class="btn btn-xs btn-outline-primary" title="' . $str('fullreport') . '">📊</a>
        </td>
    </tr>';
}
echo '</tbody></table>';
echo '</div>';

echo $OUTPUT->footer();
