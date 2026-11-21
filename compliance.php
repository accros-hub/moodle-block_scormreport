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
if (!\block_scormreport\license::has_any_access()) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

// Filter: active | recent (last 60 days) | all. Default: recent.
$filter = optional_param('filter', 'recent', PARAM_ALPHA);
if (!in_array($filter, ['active', 'recent', 'all'])) {
    $filter = 'recent';
}

$str = function ($key, $a = null) {
    return get_string($key, 'block_scormreport', $a);
};
$aptthreshold = get_config('block_scormreport', 'apt_threshold');
$aptthreshold = ($aptthreshold === false || $aptthreshold === '') ? 75 : (int)$aptthreshold;
$aptthreshold = max(0, min(100, $aptthreshold));

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
$seesall = is_siteadmin();
$owncourseids = null; // null = no restriction; array = restrict to these ids.

if (!$seesall) {
    $rolesall = get_config('block_scormreport', 'dashboard_roles_all');
    $rolesown = get_config('block_scormreport', 'dashboard_roles_own');
    $rolesall = $rolesall ? explode(',', $rolesall) : [];
    $rolesown = $rolesown ? explode(',', $rolesown) : [];

    // Does the user hold any "see all" role anywhere at system level?
    if (!empty($rolesall)) {
        $sysroles = $DB->get_fieldset_select(
            'role_assignments',
            'roleid',
            'userid = :uid',
            ['uid' => $USER->id]
        );
        foreach ($rolesall as $rid) {
            if (in_array($rid, $sysroles)) {
                $seesall = true;
                break;
            }
        }
    }

    if (!$seesall) {
        // Collect courses where the user is enrolled with any "see own" role.
        $owncourseids = [];
        if (!empty($rolesown)) {
            [$rinsql, $rparams] = $DB->get_in_or_equal($rolesown, SQL_PARAMS_NAMED, 'r');
            $rparams['uid'] = $USER->id;
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT ctx.instanceid AS courseid
                   FROM {role_assignments} ra
                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                  WHERE ra.userid = :uid AND ra.roleid $rinsql",
                $rparams
            );
            foreach ($rows as $row) {
                $owncourseids[] = $row->courseid;
            }
        }
        // If the user has no qualifying role and no own courses, show nothing.
        if (empty($owncourseids)) {
            $owncourseids = [-1]; // impossible id → empty result
        }
    }
}

// Apply role restriction to the WHERE clause.
if (!$seesall && $owncourseids !== null) {
    [$cinsql, $cparams] = $DB->get_in_or_equal($owncourseids, SQL_PARAMS_NAMED, 'oc');
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
    if (!$cctx) {
        continue;
    }

    try {
        $rd = block_scormreport_get_data($c->id, 0, 0, $cctx, $c, $DB, $CFG);
    } catch (\Exception $e) {
        continue;
    }

    $numstudents = count($rd['students']);
    if ($numstudents === 0) {
        continue;
    }

    $apto = 0;
    $noapto = 0;
    $risk = 0;
    $noactivity = 0;
    $deadlinetolerance = get_config('block_scormreport', 'deadline_tolerance');
    $deadlinetolerance = ($deadlinetolerance === false || $deadlinetolerance === '') ? 15 : (int)$deadlinetolerance;
    $deadlinetolerance = max(0, min(100, $deadlinetolerance));

    foreach ($rd['globaldata'] as $row) {
        $pct = (float)rtrim($row['pctsuperados'], '%');
        if ($pct >= $aptthreshold) {
            $apto++;
        } else {
            $noapto++;
        }
        // Deadline risk
        $dl = block_scormreport_calc_deadline_status($pct, $c->startdate ?? 0, $c->enddate ?? 0, $deadlinetolerance);
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

// Render structural markup through a Moodle Mustache template.
$filteroptions = [
    'active' => $str('filter_active'),
    'recent' => $str('filter_recent'),
    'all' => $str('filter_all'),
];
$filters = [];
foreach ($filteroptions as $key => $label) {
    $filters[] = [
        'url' => (new moodle_url('/blocks/scormreport/compliance.php', ['filter' => $key]))->out(false),
        'label' => $label,
        'buttonclass' => $filter === $key ? 'btn-primary' : 'btn-outline-secondary',
    ];
}
$cards = [
    ['label' => $str('totalcourses'), 'value' => $totals['courses'], 'color' => '#0073aa'],
    ['label' => $str('totalstudents'), 'value' => $totals['students'], 'color' => '#2196F3'],
    ['label' => $str('apt'), 'value' => $totals['apto'], 'color' => '#28a745'],
    ['label' => $str('notapt'), 'value' => $totals['noapto'], 'color' => '#dc3545'],
    ['label' => $str('studentsatrisk'), 'value' => $totals['risk'], 'color' => '#e67e22'],
    ['label' => $str('noactivitylabel'), 'value' => $totals['nodata'], 'color' => '#6c757d'],
];
$alerts = [];
foreach ($stats as $item) {
    if ($item['risk'] <= 0 && $item['noactivity'] <= 2) {
        continue;
    }
    $parts = [];
    if ($item['risk'] > 0) {
        $parts[] = $item['risk'] . ' ' . strtolower($str('studentsatrisk'));
    }
    if ($item['noactivity'] > 0) {
        $parts[] = $item['noactivity'] . ' ' . strtolower($str('noactivitylabel'));
    }
    $alerts[] = ['coursename' => $item['coursename'], 'description' => implode(', ', $parts)];
}
$headers = [];
foreach (
    [
    'coursename_label', 'coursedates_label', 'totalstudents', 'apt', 'notapt',
    'apt', 'studentsatrisk', 'noactivitylabel', 'actions',
    ] as $index => $key
) {
    $headers[] = [
        'label' => $index === 5 ? '% ' . $str($key) : $str($key),
        'class' => $index === 0 ? '' : 'text-center',
    ];
}
$courses = [];
foreach ($stats as $item) {
    $item['reporturl'] = (new moodle_url(
        '/blocks/scormreport/report.php',
        ['courseid' => $item['courseid']]
    ))->out(false);
    $item['pctcolor'] = $item['pctapto'] >= 80 ? '#28a745'
        : ($item['pctapto'] >= 50 ? '#ffc107' : '#dc3545');
    $item['riskclass'] = $item['risk'] > 0 ? 'badge-danger' : 'badge-success';
    $item['noclass'] = $item['noactivity'] > 0 ? 'badge-warning' : 'badge-success';
    $item['fullreport'] = $str('fullreport');
    $courses[] = $item;
}
$lockednotice = $lockedcourses > 0
    ? $OUTPUT->notification($str('compliance_locked_courses', $lockedcourses), 'warning', false) : '';
echo $OUTPUT->render_from_template('block_scormreport/compliance_dashboard', [
    'title' => $str('compliancetitle'),
    'filterlabel' => $str('filtershow'),
    'filters' => $filters,
    'lockednotice' => $lockednotice,
    'cards' => $cards,
    'hasalerts' => !empty($alerts),
    'alerts' => $alerts,
    'alertslabel' => '⚠ ' . $str('compliancealerts'),
    'headers' => $headers,
    'courses' => $courses,
]);

echo $OUTPUT->footer();
