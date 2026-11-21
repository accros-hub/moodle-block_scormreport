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
 * SCORM report page for teachers.
 * All data is now computed centrally via lib_report.php / block_scormreport_get_data().
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');

$courseid = required_param('courseid', PARAM_INT);
$download = optional_param('download', 0, PARAM_INT);
$userid   = optional_param('userid', 0, PARAM_INT);
$scormid  = optional_param('scormid', 0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/scormreport:viewreports', $context);

if ($download) {
    require_capability('block/scormreport:export', $context);
    // Exports follow the same license rule as the report (defence in depth).
    if (!\block_scormreport\license::course_allowed($course)) {
        throw new moodle_exception('licenselocked', 'block_scormreport');
    }
}

$isdetail = ($userid > 0 || $scormid > 0);

// Capabilities to control button visibility
$canexport = has_capability('block/scormreport:export', $context);
$cansendreminders = has_capability('block/scormreport:sendreminders', $context);

// ── License check: courses not covered by the license show the locked page. ──
if (!\block_scormreport\license::course_allowed($course)) {
    block_scormreport_show_locked_page($PAGE, $OUTPUT, $course, $courseid);
    // block_scormreport_show_locked_page() calls exit.
}

// ── Column preferences (per user) ──
// Optional columns the user can show/hide. Fixed columns (student, topics,
// fullreport, logentries) are always shown and not listed here.
// Truncate a company name to a maximum length with an ellipsis.
$company_short = function($name, $max = 30) {
    $name = (string)$name;
    if (mb_strlen($name) <= $max) return $name;
    return mb_substr($name, 0, $max - 1) . '…';
};

$optional_columns = ['username', 'email', 'dni', 'company', 'timescorm',
    'pcttimecourse', 'score', 'pctvistos', 'pctsuperados', 'seen', 'passed',
    'attempts_total', 'firstaccess', 'lastaccess', 'progresscolumn',
    'deadlinestatus', 'evidencebutton', 'connections', 'diploma'];

// Columns shown by default the first time (keep the original compact view).
$default_columns = ['company', 'timescorm', 'pcttimecourse', 'score',
    'pctsuperados', 'firstaccess', 'lastaccess', 'progresscolumn',
    'deadlinestatus', 'evidencebutton', 'connections', 'diploma'];

// Save new column selection if submitted.
$savecols = optional_param('savecols', 0, PARAM_INT);
if ($savecols && confirm_sesskey()) {
    $selected = optional_param_array('cols', [], PARAM_ALPHANUMEXT);
    // Keep only valid optional column keys.
    $selected = array_values(array_intersect($selected, $optional_columns));
    set_user_preference('block_scormreport_columns', implode(',', $selected));
    redirect(new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]));
}

// Load the user's column preference (default: all optional columns visible).
$colpref = get_user_preferences('block_scormreport_columns', null);
if ($colpref === null) {
    $visible_cols = $default_columns; // first time: show the default compact set
} else {
    $visible_cols = ($colpref === '') ? [] : explode(',', $colpref);
}
// Helper: is an optional column visible?
$show_col = function($key) use ($visible_cols) {
    return in_array($key, $visible_cols);
};

// Configurable settings
$apt_threshold = get_config('block_scormreport', 'apt_threshold');
$apt_threshold = ($apt_threshold === false || $apt_threshold === '') ? 75 : (int)$apt_threshold;
$apt_threshold = max(0, min(100, $apt_threshold));
$deadline_tolerance = get_config('block_scormreport', 'deadline_tolerance');
$deadline_tolerance = ($deadline_tolerance === false || $deadline_tolerance === '') ? 15 : (int)$deadline_tolerance;
$deadline_tolerance = max(0, min(100, $deadline_tolerance));

// Determine if the course has ended (final Apt/Not-apt) or is still active (threshold labels).
$course_ended = (!empty($course->enddate) && $course->enddate < time());
$progress_ok_label  = $course_ended ? get_string('final_apt', 'block_scormreport')    : get_string('inprogress_ok', 'block_scormreport');
$progress_no_label  = $course_ended ? get_string('final_notapt', 'block_scormreport') : get_string('inprogress_no', 'block_scormreport');

// Toggle automatic reminders
$autoreminder_toggle = optional_param('autoreminder_toggle', -1, PARAM_INT);
if ($autoreminder_toggle >= 0 && $cansendreminders && is_siteadmin()) {
    require_sesskey();
    set_config('autoreminder_enabled', $autoreminder_toggle ? 1 : 0, 'block_scormreport');
    $redirecturl = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
    redirect($redirecturl);
}
$autoreminder_enabled = (bool)get_config('block_scormreport', 'autoreminder_enabled');

// Detect if mod_diploma is installed and the course has a diploma activity
$has_diploma = false;
$diploma_cmid = 0;
if ($DB->record_exists('modules', ['name' => 'diploma'])) {
    $diploma_cms = $DB->get_records_sql(
        "SELECT cm.id FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid AND m.name = 'diploma'
            AND cm.deletioninprogress = 0",
        ['courseid' => $courseid], 0, 1
    );
    if (!empty($diploma_cms)) {
        $diploma_cm = reset($diploma_cms);
        $has_diploma = true;
        $diploma_cmid = $diploma_cm->id;
    }
}

// ─── All data from central function ─────────────────────────────────────────
$reportdata  = block_scormreport_get_data($courseid, $userid, $scormid, $context, $course, $DB, $CFG);
$students    = $reportdata['students'];
$scorms      = $reportdata['scorms'];
$globaldata  = $reportdata['globaldata'];
$detaildata  = $reportdata['detaildata'];
$coursehours = $reportdata['coursehours'];
$totalscorms = $reportdata['totalscorms'];

// ─── Helper: badge by status ─────────────────────────────────────────────────

// ─── Excel export ─────────────────────────────────────────────────────────
if ($download) {

    // PhpSpreadsheet ships with Moodle and is loaded by the core autoloader.
    require_once($CFG->libdir . '/excellib.class.php');
    $phpspreadsheet_loaded = class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet');

    if ($phpspreadsheet_loaded) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(get_string('sheettitle', 'block_scormreport'));

        $headerstyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => '0073AA']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $centerstyle = [
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $altrowstyle = [
            'fill' => ['fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                       'startColor' => ['rgb' => 'EBF5FB']],
        ];
        $linkstyle = [
            'font' => ['color' => ['rgb' => '0563C1'], 'underline' => true],
        ];

        if ($isdetail) {
            // Detail view: no links (not applicable per individual SCORM)
            $headers = [
                get_string('coursename',   'block_scormreport'),
                get_string('student',      'block_scormreport'),
                get_string('company',      'block_scormreport'),
                get_string('dni',          'block_scormreport'),
                get_string('email',        'block_scormreport'),
                get_string('scormname',    'block_scormreport'),
                get_string('timescorm',    'block_scormreport'),
                get_string('score',        'block_scormreport'),
                get_string('status',       'block_scormreport'),
                get_string('firstaccess',  'block_scormreport'),
                get_string('lastaccess',   'block_scormreport'),
            ];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle('A1:K1')->applyFromArray($headerstyle);
            $rownum = 2;
            foreach ($detaildata as $row) {
                $sheet->fromArray(array_map("block_scormreport_escape_spreadsheet", [
                    $course->fullname,
                    $row['studentname'], $row['company'] ?? '', $row['dni'], $row['email'], $row['scormname'],
                    $row['timescorm'],   $row['score'],     $row['status'],
                    $row['firstaccess'], $row['lastaccess'],
                ]), null, 'A' . $rownum);
                $sheet->getStyle('G'.$rownum.':I'.$rownum)->applyFromArray($centerstyle);
                if ($rownum % 2 === 0) {
                    $sheet->getStyle('A'.$rownum.':K'.$rownum)->applyFromArray($altrowstyle);
                }
                $rownum++;
            }
            foreach (range('A','J') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

        } else {
            // Global view: columns follow the user's column selection.
            // Fixed: Course, Student. Then the selected optional columns.
            // Fixed at the end: full report + log entries links.
            $colmap = [
                'username'     => ['label' => get_string('username', 'block_scormreport'),     'val' => fn($r) => $r['username'] ?? ''],
                'email'        => ['label' => get_string('email', 'block_scormreport'),        'val' => fn($r) => $r['email'] ?? ''],
                'dni'          => ['label' => get_string('dni', 'block_scormreport'),          'val' => fn($r) => $r['dni'] ?? ''],
                'company'      => ['label' => get_string('company', 'block_scormreport'),      'val' => fn($r) => $r['company'] ?? ''],
                'timescorm'    => ['label' => get_string('timescorm', 'block_scormreport'),    'val' => fn($r) => $r['timescorm']],
                'pcttimecourse'=> ['label' => get_string('pcttimecourse', 'block_scormreport'),'val' => fn($r) => $r['pcttotal']],
                'score'        => ['label' => get_string('score', 'block_scormreport'),        'val' => fn($r) => $r['score']],
                'pctvistos'    => ['label' => get_string('pctvistos', 'block_scormreport'),    'val' => fn($r) => $r['pctvistos']],
                'pctsuperados' => ['label' => get_string('pctsuperados', 'block_scormreport'), 'val' => fn($r) => $r['pctsuperados']],
                'seen'         => ['label' => get_string('topicsseen', 'block_scormreport'),   'val' => fn($r) => $r['seen'] ?? 0],
                'passed'       => ['label' => get_string('topicspassed', 'block_scormreport'), 'val' => fn($r) => $r['passed'] ?? 0],
                'attempts_total'=> ['label' => get_string('attemptstotal', 'block_scormreport'),'val' => fn($r) => $r['attempts_total'] ?? 0],
                'firstaccess'  => ['label' => get_string('firstaccess', 'block_scormreport'),  'val' => fn($r) => $r['firstaccess']],
                'lastaccess'   => ['label' => get_string('lastaccess', 'block_scormreport'),   'val' => fn($r) => $r['lastaccess']],
            ];
            // Build the ordered list of active optional columns for Excel.
            $excelcols = [];
            foreach (['username','email','dni','company','timescorm','pcttimecourse',
                      'score','pctvistos','pctsuperados','seen','passed','attempts_total',
                      'firstaccess','lastaccess'] as $ck) {
                if ($show_col($ck)) $excelcols[] = $ck;
            }

            // Header row.
            $headers = [get_string('coursename', 'block_scormreport'), get_string('student', 'block_scormreport')];
            foreach ($excelcols as $ck) $headers[] = $colmap[$ck]['label'];
            $headers[] = get_string('fullreport', 'block_scormreport');
            $headers[] = get_string('logentries', 'block_scormreport');
            $ncols = count($headers);
            $lastcol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ncols);

            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle('A1:' . $lastcol . '1')->applyFromArray($headerstyle);
            $rownum = 2;
            foreach ($globaldata as $row) {
                $line = [$course->fullname, $row['studentname']];
                foreach ($excelcols as $ck) $line[] = $colmap[$ck]['val']($row);
                $line[] = get_string('fullreport', 'block_scormreport');
                $line[] = get_string('logentries', 'block_scormreport');
                $sheet->fromArray(array_map("block_scormreport_escape_spreadsheet", $line), null, 'A' . $rownum);

                // Hyperlinks go in the last two columns.
                $linkcol1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ncols - 1);
                $linkcol2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ncols);
                $sheet->getCell($linkcol1 . $rownum)->getHyperlink()->setUrl($row['fullreport']);
                $sheet->getCell($linkcol2 . $rownum)->getHyperlink()->setUrl($row['logentries']);
                $sheet->getStyle($linkcol1 . $rownum . ':' . $linkcol2 . $rownum)->applyFromArray($linkstyle);

                if ($rownum % 2 === 0) {
                    $sheet->getStyle('A'.$rownum.':'.$lastcol.$rownum)->applyFromArray($altrowstyle);
                }
                $rownum++;
            }
            foreach (range('A', $lastcol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $lastrow = $sheet->getHighestRow() + 1;
        $sheet->setCellValue('A'.$lastrow,
            get_string('totalstudents','block_scormreport').': '.count($students).
            '   |   '.get_string('totalscorms','block_scormreport').': '.$totalscorms
        );
        $sheet->getStyle('A'.$lastrow)->getFont()->setItalic(true);

        // ── Sheet 2: SCO matrix (student x SCORM) ──
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle(get_string('topicscolumn', 'block_scormreport'));
        $matrixheaders = [
            get_string('student', 'block_scormreport'),
            get_string('dni', 'block_scormreport'),
            get_string('email', 'block_scormreport'),
            get_string('scormname', 'block_scormreport'),
            get_string('timescorm', 'block_scormreport'),
            get_string('score', 'block_scormreport'),
            get_string('status', 'block_scormreport'),
            get_string('firstaccess', 'block_scormreport'),
            get_string('lastaccess', 'block_scormreport'),
        ];
        $sheet2->fromArray($matrixheaders, null, 'A1');
        $sheet2->getStyle('A1:I1')->applyFromArray($headerstyle);
        $mrow = 2;
        foreach ($globaldata as $row) {
            if (empty($row['scormdetails'])) continue;
            foreach ($row['scormdetails'] as $sd) {
                $badgeinfo = block_scormreport_get_badge_info($sd['status']);
                $sheet2->fromArray(array_map('block_scormreport_escape_spreadsheet', [
                    $row['studentname'], $row['dni'], $row['email'],
                    $sd['scormname'], $sd['timescorm'], $sd['score'],
                    $badgeinfo['label'], $sd['firstaccess'], $sd['lastaccess'],
                ]), null, 'A' . $mrow);
                $sheet2->getStyle('E'.$mrow.':G'.$mrow)->applyFromArray($centerstyle);
                if ($mrow % 2 === 0) {
                    $sheet2->getStyle('A'.$mrow.':I'.$mrow)->applyFromArray($altrowstyle);
                }
                $mrow++;
            }
        }
        foreach (range('A','I') as $col) $sheet2->getColumnDimension($col)->setAutoSize(true);
        $spreadsheet->setActiveSheetIndex(0); // Back to the first sheet

        $filename = clean_filename('scorm_report_'.$course->shortname.'_'.date('Ymd_His'));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;

    } else {
        // Fallback: CSV with semicolons
        $filename = clean_filename('scorm_report_'.$course->shortname.'_'.date('Ymd_His'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');
        if ($isdetail) {
            fputcsv($fp, [
                get_string('coursename',  'block_scormreport'),
                get_string('student',     'block_scormreport'),
                get_string('company',     'block_scormreport'),
                get_string('dni', 'block_scormreport'),
                get_string('email',       'block_scormreport'),
                get_string('scormname',   'block_scormreport'),
                get_string('timescorm',   'block_scormreport'),
                get_string('score',       'block_scormreport'),
                get_string('status',      'block_scormreport'),
                get_string('firstaccess', 'block_scormreport'),
                get_string('lastaccess',  'block_scormreport'),
            ], ';');
            foreach ($detaildata as $row) {
                fputcsv($fp, array_map('block_scormreport_escape_spreadsheet', [
                    $course->fullname,
                    $row['studentname'], $row['company'] ?? '', $row['dni'], $row['email'], $row['scormname'],
                    $row['timescorm'],   $row['score'],     $row['status'],
                    $row['firstaccess'], $row['lastaccess'],
                ]), ';');
            }
        } else {
            fputcsv($fp, [
                get_string('coursename',   'block_scormreport'),
                get_string('student',      'block_scormreport'),
                get_string('company',      'block_scormreport'),
                get_string('dni', 'block_scormreport'),
                get_string('email',        'block_scormreport'),
                get_string('timescorm',    'block_scormreport'),
                get_string('pcttimecourse', 'block_scormreport'),
                get_string('score',        'block_scormreport'),
                get_string('pctvistos',    'block_scormreport'),
                get_string('pctsuperados', 'block_scormreport'),
                get_string('firstaccess',  'block_scormreport'),
                get_string('lastaccess',   'block_scormreport'),
                get_string('fullreport',   'block_scormreport'),
                get_string('logentries',   'block_scormreport'),
            ], ';');
            foreach ($globaldata as $row) {
                fputcsv($fp, array_map('block_scormreport_escape_spreadsheet', [
                    $course->fullname,
                    $row['studentname'], $row['company'] ?? '', $row['dni'], $row['email'],
                    $row['timescorm'], $row['pcttotal'],
                    $row['score'],       $row['pctvistos'],  $row['pctsuperados'],
                    $row['firstaccess'], $row['lastaccess'],
                    $row['fullreport'],  $row['logentries'],
                ]), ';');
            }
        }
        fclose($fp);
        exit;
    }
}
// ─── HTML ─────────────────────────────────────────────────────────────────────
$PAGE->set_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_title(get_string('reporttitle', 'block_scormreport'));
$PAGE->set_heading($course->fullname . ' — ' . get_string('reporttitle', 'block_scormreport'));

$PAGE->add_body_class('block_scormreport');
echo $OUTPUT->header();

// Trial countdown banner (only during an active trial period).
if (\block_scormreport\license::is_valid() && \block_scormreport\license::get_type() === 'trial') {
    $daysleft = \block_scormreport\license::days_left();
    echo '<div style="background:#fff3cd;border:1px solid #ffe69c;color:#856404;
        border-radius:10px;padding:10px 16px;margin-bottom:16px;font-weight:600;
        display:inline-flex;align-items:center;gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        ' . get_string('trial_days_left', 'block_scormreport', $daysleft) . '
    </div>';
}

$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
echo html_writer::link($courseurl, get_string('back', 'block_scormreport'),
    ['class' => 'btn btn-secondary mb-3']);

// Columns selector panel (global view only). The toggle button is rendered
// later, in the action buttons row.
if (!$isdetail) {
    // Panel (hidden by default). Order here is the display order in the panel.
    $collabels = [
        'username'       => get_string('username', 'block_scormreport'),
        'email'          => get_string('email', 'block_scormreport'),
        'dni'            => get_string('dni', 'block_scormreport'),
        'company'        => get_string('company', 'block_scormreport'),
        'timescorm'      => get_string('timescorm', 'block_scormreport'),
        'pcttimecourse'  => get_string('pcttimecourse', 'block_scormreport'),
        'score'          => get_string('score', 'block_scormreport'),
        'pctvistos'      => get_string('pctvistos', 'block_scormreport'),
        'pctsuperados'   => get_string('pctsuperados', 'block_scormreport'),
        'seen'           => get_string('topicsseen', 'block_scormreport'),
        'passed'         => get_string('topicspassed', 'block_scormreport'),
        'attempts_total' => get_string('attemptstotal', 'block_scormreport'),
        'firstaccess'    => get_string('firstaccess', 'block_scormreport'),
        'lastaccess'     => get_string('lastaccess', 'block_scormreport'),
        'progresscolumn' => get_string('progresscolumn', 'block_scormreport'),
        'deadlinestatus' => get_string('deadlinestatus', 'block_scormreport'),
        'evidencebutton' => get_string('evidencebutton', 'block_scormreport'),
        'connections'    => get_string('connections', 'block_scormreport'),
        'diploma'        => get_string('diploma', 'block_scormreport'),
    ];
    // Offer only the columns that can appear for this user and course.
    if (!$has_diploma) {
        // The Diploma column needs mod_diploma and a diploma activity in this course.
        unset($collabels['diploma']);
    }
    if (!$canexport) {
        // Evidence and connection buttons need the export capability.
        unset($collabels['evidencebutton'], $collabels['connections']);
    }
    $colform = new moodle_url('/blocks/scormreport/report.php');
    // Modal overlay (centered floating window).
    echo '<div id="cols-modal-overlay" style="display:none;position:fixed;inset:0;
        background:rgba(0,0,0,0.5);z-index:10500;align-items:flex-start;justify-content:center;
        padding:40px 16px;overflow:auto;">
        <div role="dialog" aria-modal="true" style="background:#fff;border-radius:14px;
            width:100%;max-width:720px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">
            <div style="background:#002f4b;color:#fff;padding:16px 22px;display:flex;
                align-items:center;justify-content:space-between;">
                <span style="font-weight:700;font-size:1.1rem;">' . get_string('choosecolumns_desc', 'block_scormreport') . '</span>
                <button type="button" id="cols-modal-close" aria-label="Close" style="background:none;border:none;
                    color:#fff;font-size:1.6rem;line-height:1;cursor:pointer;padding:0 4px;">&times;</button>
            </div>
            <form method="post" action="' . $colform->out(false) . '">
                <input type="hidden" name="courseid" value="' . $courseid . '">
                <input type="hidden" name="sesskey" value="' . sesskey() . '">
                <input type="hidden" name="savecols" value="1">
                <div style="padding:22px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px 18px;">';
    foreach ($collabels as $key => $label) {
        $checked = $show_col($key) ? ' checked' : '';
        echo '<label style="display:inline-flex;align-items:center;gap:8px;font-weight:500;
            padding:6px 10px;border:1px solid #e3e8ee;border-radius:8px;cursor:pointer;">
            <input type="checkbox" name="cols[]" value="' . $key . '"' . $checked . '> ' . $label . '</label>';
    }
    // Columns not offered here keep their saved state (for example Diploma in a
    // course without a diploma activity stays on for the courses that have one).
    foreach ($optional_columns as $key) {
        if (!isset($collabels[$key]) && $show_col($key)) {
            echo '<input type="hidden" name="cols[]" value="' . $key . '">';
        }
    }
    echo '        </div>
                <div style="padding:16px 22px;border-top:1px solid #e3e8ee;display:flex;
                    justify-content:flex-end;gap:10px;background:#f8f9fa;">
                    <button type="button" id="cols-modal-cancel" class="btn btn-outline-secondary">'
                        . get_string('cancel', 'block_scormreport') . '</button>
                    <button type="submit" class="btn btn-primary">'
                        . get_string('savecolumns', 'block_scormreport') . '</button>
                </div>
            </form>
        </div>
    </div>';
}

// Get course dates
$startdate = !empty($course->startdate) ? userdate($course->startdate, '%d/%m/%Y') : '-';
$enddate   = !empty($course->enddate)   ? userdate($course->enddate,   '%d/%m/%Y') : '-';

// ($coursehours is loaded at the top of the file.)

// Dynamic title
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

// Summary cards. When filtering by a single student, show that student's data.
if ($userid > 0 && count($globaldata) === 1) {
    // Per-student cards (like the student view).
    $srow = reset($globaldata);
    echo '
<div class="scorm-summary-cards mb-4">
    <div class="summary-card">
        <span class="card-value">' . $srow['timescorm'] . '</span>
        <span class="card-label">' . get_string('timescorm', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $srow['score'] . '</span>
        <span class="card-label">' . get_string('score', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $srow['pctsuperados'] . '</span>
        <span class="card-label">' . get_string('pctsuperados', 'block_scormreport') . '</span>
    </div>
</div>';
} else {
    // Global course cards.
    $avgvistos    = 0;
    $avgsuperados = 0;
    if (!empty($globaldata)) {
        $avgvistos    = round(array_sum(array_map(
            fn($r) => (float)rtrim($r['pctvistos'],    '%'), $globaldata)) / count($globaldata), 1);
        $avgsuperados = round(array_sum(array_map(
            fn($r) => (float)rtrim($r['pctsuperados'], '%'), $globaldata)) / count($globaldata), 1);
    }
    echo '
<div class="scorm-summary-cards mb-4">
    <div class="summary-card">
        <span class="card-value">' . count($students) . '</span>
        <span class="card-label">' . get_string('totalstudents', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $totalscorms . '</span>
        <span class="card-label">' . get_string('totalscorms', 'block_scormreport') . '</span>
    </div>
    <div class="summary-card">
        <span class="card-value">' . $avgsuperados . '%</span>
        <span class="card-label">' . get_string('avgsuperados', 'block_scormreport') . '</span>
    </div>
</div>';
}

// Filters
echo '
<form method="get" action="" class="scorm-filters mb-3">
    <input type="hidden" name="courseid" value="' . $courseid . '">
    <div class="filter-row" style="flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
        <label>' . get_string('filterby', 'block_scormreport') . '
            <select name="userid" class="form-control" onchange="this.form.submit()">
                <option value="0">' . get_string('allstudents', 'block_scormreport') . '</option>';
$sorted_students = $students;
usort($sorted_students, function($a, $b) {
    return strcasecmp(fullname($a), fullname($b));
});
foreach ($sorted_students as $s) {
    $sel = ($userid == $s->id) ? ' selected' : '';
    echo '<option value="' . $s->id . '"' . $sel . '>'
        . htmlspecialchars(fullname($s)) . '</option>';
}
echo '      </select></label>
        <label>' . get_string('scormfilter', 'block_scormreport') . '
            <select name="scormid" class="form-control" onchange="this.form.submit()">
                <option value="0">' . get_string('allscorms', 'block_scormreport') . '</option>';
foreach ($scorms as $sc) {
    $sel = ($scormid == $sc->id) ? ' selected' : '';
    echo '<option value="' . $sc->id . '"' . $sel . '>'
        . htmlspecialchars($sc->name) . '</option>';
}
echo '      </select></label>';

// Company filter (JS, global view only).
if (!$isdetail) {
    $companies = [];
    foreach ($globaldata as $r) {
        if (!empty($r['company'])) $companies[$r['company']] = true;
    }
    $companies = array_keys($companies);
    sort($companies, SORT_NATURAL | SORT_FLAG_CASE);
    echo '<label class="filter-label">' . get_string('filtercompany', 'block_scormreport') . '
        <select id="js-filter-company" class="form-control filter-select-wide">
            <option value="">' . get_string('filterallcompanies', 'block_scormreport') . '</option>';
    foreach ($companies as $comp) {
        echo '<option value="' . htmlspecialchars(strtolower($comp), ENT_QUOTES) . '" title="' . htmlspecialchars($comp, ENT_QUOTES) . '">' . htmlspecialchars($company_short($comp)) . '</option>';
    }
    echo '</select></label>';
}

// Inline filter JS (global view only)
if (!$isdetail) {
    echo '
        <label class="filter-label">
            ' . get_string('filterminpassed', 'block_scormreport') . '
            <select id="js-filter-vistos" class="form-control filter-select">
                <option value="0">' . get_string('filternominscore', 'block_scormreport') . '</option>
                <option value="50">≥ 50%</option>
                <option value="75">≥ 75%</option>
                <option value="100">100%</option>
            </select>
        </label>
        <label class="filter-label">
            ' . get_string('filterminscore', 'block_scormreport') . '
            <select id="js-filter-nota" class="form-control filter-select">
                <option value="0">' . get_string('filternominscore', 'block_scormreport') . '</option>
                <option value="25">≥ 25</option>
                <option value="50">≥ 50</option>
                <option value="75">≥ 75</option>
                <option value="100">100</option>
            </select>
        </label>
        <label class="filter-label">
            ' . get_string('filterdisplay', 'block_scormreport') . '
            <select id="js-filter-apto" class="form-control filter-select-wide">
                <option value="all">' . get_string('filterall', 'block_scormreport') . '</option>
                <option value="apto">' . get_string('onlyapt', 'block_scormreport') . '</option>
                <option value="noapto">' . get_string('onlynotapt', 'block_scormreport') . '</option>
            </select>
        </label>';
}

$clearurl = new moodle_url('/blocks/scormreport/report.php', ['courseid' => $courseid]);
echo '<label style="display:flex;flex-direction:column;justify-content:flex-end;">
            <span style="visibility:hidden;font-size:0.85rem;">.</span>'
    . html_writer::link($clearurl,
        '✕ ' . get_string('clearfilters', 'block_scormreport'),
        ['class' => 'btn', 'style' => 'background:#8b0000;border:1px solid #8b0000;color:#fff;font-weight:600;white-space:nowrap;'])
    . '  </label>
    </div>
    <div id="js-filter-count" class="mt-2 text-muted-sub"></div>
</form>';

if (!$isdetail) {
// Prepare parameters for the AMD module
$jsparams = [
    'showingTemplate' => get_string('showingxofy', 'block_scormreport', (object)['visible' => '__VISIBLE__', 'total' => '__TOTAL__']),
    'aptLabel'        => get_string('apt', 'block_scormreport'),
    'notaptLabel'     => get_string('notapt', 'block_scormreport'),
    'aptThreshold'    => $apt_threshold,
    'strViewtopics'   => get_string('viewtopics', 'block_scormreport'),
    'strHidetopics'   => get_string('hidetopics', 'block_scormreport'),
];
}

// Columns button (first in the action row, global view only).
if (!$isdetail) {
    $colicon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><rect x="3" y="3" width="7" height="18"/><rect x="14" y="3" width="7" height="18"/></svg>';
    echo '<button type="button" id="cols-toggle-btn" class="btn mb-3" '
        . 'style="background:#0073aa;border-color:#0073aa;color:#fff;font-weight:600;">'
        . '<span style="display:inline-flex;align-items:center;gap:6px;">' . $colicon . ' ' . get_string('choosecolumns', 'block_scormreport') . '</span></button> ';
}

// Export Excel
if ($canexport) {
    $exporturl = new moodle_url('/blocks/scormreport/report.php', [
        'courseid' => $courseid, 'userid' => $userid,
        'scormid'  => $scormid,  'download' => 1,
    ]);
    $excel_icon = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="24" height="24" rx="4" fill="#1D6F42"/>'
        . '<text x="12" y="16" font-size="11" font-weight="bold" fill="white" text-anchor="middle" font-family="Arial,sans-serif">X</text>'
        . '<polyline points="16,14 19,17 16,20" stroke="white" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<line x1="10" y1="17" x2="19" y2="17" stroke="white" stroke-width="1.5" stroke-linecap="round"/>'
        . '</svg>';
    $excel_label = '<span style="display:inline-flex;align-items:center;gap:8px;">'
        . $excel_icon . ' ' . get_string('exportexcel', 'block_scormreport')
        . '</span>';
    echo html_writer::link($exporturl, $excel_label,
        ['class' => 'btn btn-success mb-3',
         'style' => 'background:#1D6F42;border-color:#1D6F42;font-weight:600;']);
}

// Reminder button
if (!$isdetail && $cansendreminders) {
    $reminderurl = new moodle_url('/blocks/scormreport/send_reminder.php',
        ['courseid' => $courseid]);
    echo ' ' . html_writer::link($reminderurl,
        '<span style="display:inline-flex;align-items:center;gap:6px;">✉ ' . get_string('sendreminder', 'block_scormreport') . '</span>',
        ['class' => 'btn mb-3',
         'style' => 'background:#e67e22;border-color:#e67e22;color:#fff;font-weight:600;']);
}

// Full PDF button
if ($canexport) {
    $pdfurl = new moodle_url('/blocks/scormreport/generate_report_pdf.php',
        ['courseid' => $courseid]);
    $pdf_icon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="24" height="24" rx="4" fill="#c0392b"/>'
        . '<text x="12" y="16" font-size="9" font-weight="bold" fill="white" text-anchor="middle" font-family="Arial">PDF</text>'
        . '</svg>';
    echo ' ' . html_writer::link($pdfurl,
        '<span style="display:inline-flex;align-items:center;gap:8px;">' . $pdf_icon . ' ' . get_string('printreport', 'block_scormreport') . '</span>',
        ['class' => 'btn mb-3',
         'style' => 'background:#c0392b;border-color:#c0392b;color:#fff;font-weight:600;',
         'target' => '_blank']);
}

// Toggle automatic reminders
if ($cansendreminders && is_siteadmin()) {
    $toggleval = $autoreminder_enabled ? 0 : 1;
    $toggleurl = new moodle_url('/blocks/scormreport/report.php', [
        'courseid' => $courseid,
        'autoreminder_toggle' => $toggleval,
        'sesskey' => sesskey(),
    ]);
    $toggleclass = $autoreminder_enabled ? 'btn-success' : 'btn-outline-secondary';
    $toggleicon = $autoreminder_enabled ? '🔔' : '🔕';
    $toggletext = $autoreminder_enabled
        ? get_string('autoreminder_on', 'block_scormreport')
        : get_string('autoreminder_off', 'block_scormreport');
    echo ' ' . html_writer::link($toggleurl,
        '<span style="display:inline-flex;align-items:center;gap:6px;">' . $toggleicon . ' ' . $toggletext . '</span>',
        ['class' => 'btn mb-3 ' . $toggleclass,
         'style' => 'font-weight:600;',
         'title' => get_string('setting_autoreminder_enabled_desc', 'block_scormreport')]);
}

// Link to Training Compliance dashboard (managers/supervisors only)
if (has_capability('block/scormreport:viewcompliance', context_system::instance())) {
    $complianceurl = new moodle_url('/blocks/scormreport/compliance.php');
    $compicon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>';
    echo ' ' . html_writer::link($complianceurl,
        '<span style="display:inline-flex;align-items:center;gap:7px;">' . $compicon . ' ' . get_string('compliancelink', 'block_scormreport') . '</span>',
        ['class' => 'btn mb-3 btn-outline-info', 'style' => 'font-weight:600;']);
}

// Tutor activity: tutors of the course with their connection activity.
if (!$isdetail) {
    $tutorsurl = new moodle_url('/blocks/scormreport/tutors.php', ['courseid' => $courseid]);
    $tutoricon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2"/></svg>';
    echo ' ' . html_writer::link($tutorsurl,
        '<span style="display:inline-flex;align-items:center;gap:7px;">' . $tutoricon . ' '
            . get_string('tutoractivity', 'block_scormreport') . '</span>',
        ['class' => 'btn mb-3', 'style' => 'background:#6f42c1;border-color:#6f42c1;color:#fff;font-weight:600;']);
}

if ($isdetail) {
    echo '<div class="alert alert-info py-2 mb-3">'
        . '🔍 ' . get_string('viewingdetail', 'block_scormreport') . '</div>';
}

// ── Tabla global ──────────────────────────────────────────────────────────────
if (!$isdetail) {
    if (empty($globaldata)) {
        echo $OUTPUT->notification(get_string('nodata', 'block_scormreport'), 'info');
    } else {
        // ── Collapsible per-student progress bar chart ──
        echo '<div class="scorm-bars-wrap mb-3">';
        echo '<h6 class="chart-toggle" role="button" tabindex="0" data-target="progress-chart" aria-expanded="false" style="display:inline-flex;align-items:center;gap:8px;">'
            . '<span class="chart-toggle-arrow" style="color:#0073aa;font-size:0.9rem;transition:transform 0.2s;">▸</span>'
            . '<span style="display:inline-flex;align-items:center;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0073aa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg></span> ' . get_string('progressbystudent', 'block_scormreport') . '</h6>';
        echo '<div id="progress-chart" style="display:none;">';
        foreach ($globaldata as $grow) {
            $pspct = (float)rtrim($grow['pctsuperados'], '%');
            $fillclass = $pspct >= 80 ? 'fill-high' : ($pspct >= 40 ? 'fill-medium' : 'fill-low');
            $firstname = explode(' ', $grow['studentname'])[0];
            echo '<div class="student-bar-row">
                <div class="student-bar-name" title="' . htmlspecialchars($grow['studentname']) . '">'
                    . htmlspecialchars($firstname) . '</div>
                <div class="student-bar-track">
                    <div class="student-bar-fill ' . $fillclass . '" style="width:' . $pspct . '%"></div>
                </div>
                <div class="student-bar-pct">' . $pspct . '%</div>
            </div>';
        }
        echo '</div></div>';

        // Put multi-word column titles on two lines. A leading "% " stays with
        // the next word; otherwise split at the first space.
        $hdr = function($key) {
            $txt = get_string($key, 'block_scormreport');
            if (strpos($txt, '% ') === 0) {
                // "% Temas superados" -> "% Temas<br>superados"
                $rest = substr($txt, 2);
                $parts = explode(' ', $rest, 2);
                if (count($parts) === 2) {
                    return '% ' . $parts[0] . '<br>' . $parts[1];
                }
                return $txt;
            }
            return preg_replace('/ /', '<br>', $txt, 1);
        };
        $sorticon = ' <span class="sort-icon" aria-hidden="true">↕</span>';
        echo '
        <div class="table-responsive">
        <table class="table table-striped table-hover scorm-report-table sortable-table" id="global-table">
            <thead><tr>
                <th class="sortable-col" role="button" tabindex="0" data-col="0">' . $hdr('student')      . $sorticon . '</th>'
                . ($show_col('username')       ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('username')     . $sorticon . '</th>' : '')
                . ($show_col('email')          ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('email')        . $sorticon . '</th>' : '')
                . ($show_col('dni')            ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('dni')          . $sorticon . '</th>' : '')
                . ($show_col('company')        ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('company')      . $sorticon . '</th>' : '')
                . ($show_col('timescorm')      ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('timescorm')    . $sorticon . '</th>' : '')
                . ($show_col('pcttimecourse')  ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('pcttimecourse') . $sorticon . '</th>' : '')
                . ($show_col('score')          ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('score')        . $sorticon . '</th>' : '')
                . '<th>' . $hdr('topicscolumn') . '</th>'
                . ($show_col('pctvistos')      ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('pctvistos')    . $sorticon . '</th>' : '')
                . ($show_col('pctsuperados')   ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('pctsuperados') . $sorticon . '</th>' : '')
                . ($show_col('seen')           ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('topicsseen')   . $sorticon . '</th>' : '')
                . ($show_col('passed')         ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('topicspassed') . $sorticon . '</th>' : '')
                . ($show_col('attempts_total') ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('attemptstotal') . $sorticon . '</th>' : '')
                . ($show_col('firstaccess')    ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('firstaccess')  . $sorticon . '</th>' : '')
                . ($show_col('lastaccess')     ? '<th class="sortable-col" role="button" tabindex="0">' . $hdr('lastaccess')   . $sorticon . '</th>' : '')
                . ($show_col('progresscolumn') ? '<th>' . $hdr('progresscolumn') . '</th>' : '')
                . ($show_col('deadlinestatus') ? '<th>' . $hdr('deadlinestatus') . '</th>' : '')
                . '<th>' . $hdr('fullreport')   . '</th>'
                . '<th>' . $hdr('logentries')   . '</th>'
                . ($canexport && $show_col('evidencebutton') ? '<th>' . $hdr('evidencebutton') . '</th>' : '')
                . ($canexport && $show_col('connections') ? '<th>' . $hdr('connections') . '</th>' : '')
                . ($has_diploma && $show_col('diploma') ? '<th>' . $hdr('diploma') . '</th>' : '') . '
            </tr></thead>
            <tbody>';

        $growindex = 0;
        foreach ($globaldata as $row) {
            $growindex++;
            $ps = (float)rtrim($row['pctsuperados'], '%');
            $pspct = $ps;
            $rowclass = $pspct >= 100 ? 'row-passed' : ($pspct > 0 ? 'row-incomplete' : 'row-notattempted');
            $rowscore = ($row['score'] !== '-') ? (float)$row['score'] : 0;

            // SCOs column: dropdown button only
            $topiccell = '<button class="btn btn-xs btn-outline-info sco-toggle btn-toggle" aria-expanded="false"
                data-target="scorm-detail-' . $growindex . '"
                >' . get_string('viewtopics', 'block_scormreport') . '</button>';

            echo '<tr class="' . $rowclass . ' global-data-row"
                data-name="' . strtolower(htmlspecialchars($row['studentname'])) . '"
                data-company="' . strtolower(htmlspecialchars($row['company'] ?? '')) . '"
                data-pctsuperados="' . $pspct . '"
                data-score="' . $rowscore . '">
                <td>'                         . htmlspecialchars($row['studentname'])  . '</td>'
                . ($show_col('username')      ? '<td>' . htmlspecialchars($row['username'] ?? '') . '</td>' : '')
                . ($show_col('email')         ? '<td>' . htmlspecialchars($row['email'] ?? '') . '</td>' : '')
                . ($show_col('dni')           ? '<td>' . (!empty($row['dni']) ? htmlspecialchars($row['dni']) : '-') . '</td>' : '')
                . ($show_col('company')       ? '<td' . (!empty($row['company']) ? ' title="' . htmlspecialchars($row['company'], ENT_QUOTES) . '"' : '') . '>' . (!empty($row['company']) ? htmlspecialchars($company_short($row['company'])) : '-') . '</td>' : '')
                . ($show_col('timescorm')     ? '<td class="text-center font-monospace" data-sort="' . $row['totalseconds'] . '">' . $row['timescorm'] . '</td>' : '')
                . ($show_col('pcttimecourse') ? '<td class="text-center">' . $row['pcttotal'] . '</td>' : '')
                . ($show_col('score')         ? '<td class="text-center"><strong>' . $row['score'] . '</strong></td>' : '')
                . '<td>' . $topiccell . '</td>'
                . ($show_col('pctvistos')     ? '<td class="text-center">' . ($row['pctvistos'] ?? '-') . '</td>' : '')
                . ($show_col('pctsuperados')  ? '<td class="text-center">
                    <div class="progress-wrap">
                        <div class="progress-bar-custom bar-green" style="width:' . $ps . '%"></div>
                        <span>' . $row['pctsuperados'] . '</span>
                    </div></td>' : '')
                . ($show_col('seen')          ? '<td class="text-center">' . ($row['seen'] ?? 0) . '</td>' : '')
                . ($show_col('passed')        ? '<td class="text-center">' . ($row['passed'] ?? 0) . '</td>' : '')
                . ($show_col('attempts_total') ? '<td class="text-center">' . ($row['attempts_total'] ?? 0) . '</td>' : '')
                . ($show_col('firstaccess')   ? '<td data-sort="' . ($row['firstaccess_ts'] ?? 0) . '">' . $row['firstaccess'] . '</td>' : '')
                . ($show_col('lastaccess')    ? '<td data-sort="' . ($row['lastaccess_ts'] ?? 0) . '">' . $row['lastaccess']  . '</td>' : '')
                . ($show_col('progresscolumn') ? '<td class="text-center">'
                    . ($pspct >= $apt_threshold
                        ? '<span class="badge badge-success badge-apt">▲ ' . $progress_ok_label . '</span>'
                        : '<span class="badge badge-danger badge-apt">▼ ' . $progress_no_label . '</span>')
                    . '</td>' : '');

            // Deadline traffic-light (optional column).
            if ($show_col('deadlinestatus')) {
                $dlstatus = block_scormreport_calc_deadline_status($pspct, $course->startdate ?? 0, $course->enddate ?? 0, $deadline_tolerance);
                echo '<td class="text-center">
                        <span class="badge ' . $dlstatus['badge'] . ' badge-apt" title="' . get_string('pctdeadline', 'block_scormreport') . ': ' . $dlstatus['pctdeadline'] . '%">'
                            . $dlstatus['label'] . '</span>
                    </td>';
            }

            $iconreport = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/></svg>';
            $iconlog = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M3 3v18h18"/><path d="M7 14l3-3 3 3 4-5"/></svg>';
            echo '<td class="text-center">
                    <a href="' . $row['fullreport'] . '" target="_blank" class="btn btn-xs btn-outline-primary">
                        ' . $iconreport . ' ' . get_string('fullreport', 'block_scormreport') . '
                    </a></td>
                <td class="text-center">
                    <a href="' . $row['logentries'] . '" target="_blank" class="btn btn-xs btn-outline-secondary">
                        ' . $iconlog . ' ' . get_string('logentries', 'block_scormreport') . '
                    </a></td>
                ';
            // Compliance evidence button (per-student report)
            if ($canexport && $show_col('evidencebutton')) {
                $evidenceurl = new moodle_url('/blocks/scormreport/generate_evidence_pdf.php',
                    ['courseid' => $courseid, 'userid' => $row['userid']]);
                $evhref  = $evidenceurl->out(false);
                $evclass = 'btn btn-xs btn-outline-info';
                $evtarget = ' target="_blank"';
                echo '<td class="text-center">
                    <a href="' . $evhref . '"' . $evtarget . ' class="' . $evclass . '">
                        ' . get_string('evidencebutton', 'block_scormreport') . '
                    </a></td>';
            }
            // Connections report button (per-student daily connection PDF).
            if ($canexport && $show_col('connections')) {
                $connurl = new moodle_url('/blocks/scormreport/generate_connections_pdf.php',
                    ['courseid' => $courseid, 'userid' => $row['userid']]);
                $connhref   = $connurl->out(false);
                $connclass  = 'btn btn-xs btn-outline-secondary';
                $conntarget = ' target="_blank"';
                echo '<td class="text-center">
                    <a href="' . $connhref . '"' . $conntarget . ' class="' . $connclass . '">
                        ' . get_string('connectionsbutton', 'block_scormreport') . '
                    </a></td>';
            }
            // Diploma column if mod_diploma is available
            if ($has_diploma && $show_col('diploma')) {
                if ($pspct >= $apt_threshold) {
                    $diplomaurl = new moodle_url('/mod/diploma/view.php', ['id' => $diploma_cmid, 'userid' => $row['userid']]);
                    echo '<td class="text-center">
                        <a href="' . $diplomaurl->out(false) . '" target="_blank" class="btn btn-xs btn-outline-success">
                            🎓 ' . get_string('diploma', 'block_scormreport') . '
                        </a></td>';
                } else {
                    echo '<td class="text-center"><span class="text-muted">—</span></td>';
                }
            }
            echo '</tr>';

            // ── Expandable row: detail per SCORM ────────────────────────
            // Fixed columns: student, topics, fullreport, logentries = 4.
            $numcols = 4;
            foreach (['username','email','dni','company','timescorm','pcttimecourse',
                      'score','pctvistos','pctsuperados','seen','passed','attempts_total',
                      'firstaccess','lastaccess','progresscolumn','deadlinestatus'] as $oc) {
                if ($show_col($oc)) $numcols++;
            }
            if ($canexport && $show_col('evidencebutton')) $numcols++;
            if ($canexport && $show_col('connections')) $numcols++;
            if ($has_diploma && $show_col('diploma')) $numcols++;
            echo '<tr class="sco-detail-row" id="scorm-detail-' . $growindex . '" style="display:none;">
                <td colspan="' . $numcols . '" class="sco-detail-cell">
                <table class="table table-sm mb-0 sco-inner-table">
                    <thead class="sco-header-row"><tr>
                        <th class="sco-cell-name-wide">📎 ' . get_string('scormname', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('timescorm', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('score', 'block_scormreport') . '</th>
                        <th class="sco-cell-center">' . get_string('status', 'block_scormreport') . '</th>
                        <th class="sco-cell">' . get_string('firstaccess', 'block_scormreport') . '</th>
                        <th class="sco-cell">' . get_string('lastaccess', 'block_scormreport') . '</th>
                    </tr></thead>
                    <tbody>';

            if (!empty($row['scormdetails'])) {
                $scosubindex = 0;
                foreach ($row['scormdetails'] as $sd) {
                    $scosubindex++;
                    $sdbadge = block_scormreport_get_badge_info($sd['status']);
                    $sdname = htmlspecialchars($sd['scormname']);
                    $hasinteractions = !empty($sd['interactions']);

                    if ($sd['multisco']) {
                        $sdname .= ' <span class="badge badge-info ms-1 badge-multisco">' . get_string('multisco', 'block_scormreport') . '</span>';
                    }
                    // If the SCORM has test interactions, show "View answers".
                    // Otherwise, if it is Multi-SCO, show "View SCOs".
                    if ($hasinteractions) {
                        $sdname .= ' <button class="btn btn-xs btn-outline-primary ms-1 sco-toggle btn-toggle-sub" aria-expanded="false"
                            data-target="inter-' . $growindex . '-' . $scosubindex . '"
                            data-show="' . htmlspecialchars(get_string('viewanswers', 'block_scormreport'), ENT_QUOTES) . '"
                            data-hide="' . htmlspecialchars(get_string('hideanswers', 'block_scormreport'), ENT_QUOTES) . '"
                            >' . get_string('viewanswers', 'block_scormreport') . '</button>';
                    } else if ($sd['multisco']) {
                        $sdname .= ' <button class="btn btn-xs btn-outline-info ms-1 sco-toggle btn-toggle-sub" aria-expanded="false"
                            data-target="subsco-' . $growindex . '-' . $scosubindex . '"
                            data-show="' . htmlspecialchars(get_string('viewtopics', 'block_scormreport'), ENT_QUOTES) . '"
                            data-hide="' . htmlspecialchars(get_string('hidetopics', 'block_scormreport'), ENT_QUOTES) . '"
                            >' . get_string('viewtopics', 'block_scormreport') . '</button>';
                    }
                    echo '<tr>
                        <td class="sco-cell-name">' . $sdname . '</td>
                        <td class="sco-cell-mono">' . $sd['timescorm'] . '</td>
                        <td style="text-align:center;">' . $sd['score'] . '</td>
                        <td style="text-align:center;"><span class="badge ' . $sdbadge['badge'] . '">' . $sdbadge['label'] . '</span></td>
                        <td>' . $sd['firstaccess'] . '</td>
                        <td>' . $sd['lastaccess'] . '</td>
                    </tr>';

                    // Interactions table (test questions/answers).
                    if ($hasinteractions) {
                        echo '<tr class="sco-detail-row" id="inter-' . $growindex . '-' . $scosubindex . '" style="display:none;">
                            <td colspan="6" class="sco-subsco-cell">
                            <table class="table table-sm mb-0 sco-inner-table">
                                <thead class="answers-header"><tr>
                                    <th style="width:90px;">' . get_string('questionno', 'block_scormreport') . '</th>
                                    <th>' . get_string('markedanswer', 'block_scormreport') . '</th>
                                    <th style="text-align:center;">' . get_string('answerresult', 'block_scormreport') . '</th>
                                </tr></thead><tbody>';
                        $qn = 0;
                        foreach ($sd['interactions'] as $inter) {
                            $qn++;
                            $res = strtolower(trim($inter['result']));
                            if ($res === 'correct') {
                                $resbadge = '<span class="badge badge-success">✓ ' . get_string('answercorrect', 'block_scormreport') . '</span>';
                            } else if ($res === 'incorrect' || $res === 'wrong') {
                                $resbadge = '<span class="badge badge-danger">✗ ' . get_string('answerincorrect', 'block_scormreport') . '</span>';
                            } else {
                                $resbadge = '<span class="badge badge-secondary">' . htmlspecialchars($inter['result']) . '</span>';
                            }
                            echo '<tr>
                                <td class="sco-cell-mono" style="text-align:center;">' . $qn . '</td>
                                <td>' . s(block_scormreport_display_response($inter)) . '</td>
                                <td style="text-align:center;">' . $resbadge . '</td>
                            </tr>';
                        }
                        echo '</tbody></table></td></tr>';
                    }

                    // Sub-SCOs when the package is multi-SCO
                    if ($sd['multisco'] && !empty($sd['scos'])) {
                        echo '<tr class="sco-detail-row" id="subsco-' . $growindex . '-' . $scosubindex . '" style="display:none;">
                            <td colspan="6" class="sco-subsco-cell">
                            <table class="table table-sm mb-0 sco-inner-table">
                                <tbody>';
                        $subidx = 0;
                        foreach ($sd['scos'] as $subsco) {
                            $subidx++;
                            $subbadge = block_scormreport_get_badge_info($subsco['status']);
                            echo '<tr>
                                <td class="sco-subsco-row">↳ ' . htmlspecialchars($subsco['title']) . '</td>
                                <td class="sco-cell-mono">' . block_scormreport_hms($subsco['seconds']) . '</td>
                                <td style="text-align:center;">' . (($subsco['score'] !== false) ? $subsco['score'] : '-') . '</td>
                                <td style="text-align:center;"><span class="badge ' . $subbadge['badge'] . '">' . $subbadge['label'] . '</span></td>
                                <td>' . ($subsco['firstaccess'] ?? '-') . '</td>
                                <td>' . ($subsco['lastaccess'] ?? '-') . '</td>
                            </tr>';
                        }
                        echo '</tbody></table></td></tr>';
                    }
                }
            }

            echo '</tbody></table></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

// ── Tabla detalle ─────────────────────────────────────────────────────────────
if ($isdetail) {
    if (empty($detaildata)) {
        echo $OUTPUT->notification(get_string('nodata', 'block_scormreport'), 'info');
    } else {
        echo '
        <div class="table-responsive">
        <table class="table table-striped table-hover scorm-report-table sortable-table" id="detail-table">
            <thead><tr>
                <th class="sortable-col" role="button" tabindex="0" data-col="0">' . get_string('student',     'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="1">' . get_string('company',     'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="2">' . get_string('scormname',   'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="3">' . get_string('timescorm',   'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="4">' . get_string('score',       'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="5">' . get_string('status',      'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="6">' . get_string('firstaccess', 'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
                <th class="sortable-col" role="button" tabindex="0" data-col="7">' . get_string('lastaccess',  'block_scormreport') . ' <span class="sort-icon" aria-hidden="true">↕</span></th>
            </tr></thead>
            <tbody>';

        $rowindex = 0;
        foreach ($detaildata as $row) {
            $rowindex++;
            $badgeinfo = block_scormreport_get_badge_info($row['status']);
            $badge = $badgeinfo['badge'];
            $label = $badgeinfo['label'];

            // Name column: add Multi-SCO badge and expand button if applicable
            $namecell = htmlspecialchars($row['scormname']);
            if ($row['multisco']) {
                $namecell .= ' <span class="badge badge-info ms-1 badge-multisco">' . get_string('multisco', 'block_scormreport') . '</span>';
                $namecell .= ' <button class="btn btn-xs btn-outline-info ms-1 sco-toggle btn-toggle" aria-expanded="false"
                    data-target="sco-detail-' . $rowindex . '"
                    >' . get_string('viewtopics', 'block_scormreport') . '</button>';
            }

            $rowclass2 = '';
            switch(strtolower($row['status'])) {
                case 'passed': case 'completed': $rowclass2 = 'row-passed'; break;
                case 'failed':                   $rowclass2 = 'row-failed'; break;
                case 'incomplete':               $rowclass2 = 'row-incomplete'; break;
                default:                         $rowclass2 = 'row-notattempted'; break;
            }
            echo '<tr class="' . $rowclass2 . '">
                <td>'                                    . htmlspecialchars($row['studentname']) . '</td>
                <td>' . (!empty($row['company']) ? htmlspecialchars($row['company']) : '-') . '</td>
                <td>' . $namecell . '</td>
                <td class="text-center font-monospace" data-sort="' . ($row['seconds'] ?? 0) . '">' . $row['timescorm']   . '</td>
                <td class="text-center"><strong>'        . $row['score']       . '</strong></td>
                <td class="text-center">
                    <span class="badge ' . $badge . '">' . $label              . '</span>
                    ' . ($row['multisco'] ? '<br><small class="text-muted-sub">' . $row['pctscos'] . ' ' . get_string('scoslabel', 'block_scormreport') . '</small>' : '') . '
                </td>
                <td data-sort="' . ($row['firstaccess_ts'] ?? 0) . '">' . $row['firstaccess'] . '</td>
                <td data-sort="' . ($row['lastaccess_ts'] ?? 0) . '">' . $row['lastaccess']  . '</td>
            </tr>';

            // Expandable rows of inner SCOs (hidden by default)
            if ($row['multisco'] && !empty($row['scos'])) {
                echo '<tr id="sco-detail-' . $rowindex . '" class="sco-detail-row" style="display:none;">
                    <td colspan="7" class="sco-detail-cell">
                    <table class="table table-sm mb-0 sco-inner-table">
                        <thead class="sco-header-row">
                            <tr>
                                <th class="sco-cell-name-wide">📎 ' . get_string('scormname', 'block_scormreport') . '</th>
                                <th class="sco-cell-center">' . get_string('timescorm', 'block_scormreport') . '</th>
                                <th class="sco-cell-center">' . get_string('score', 'block_scormreport') . '</th>
                                <th class="sco-cell-center">' . get_string('status', 'block_scormreport') . '</th>
                                <th class="sco-cell">' . get_string('firstaccess', 'block_scormreport') . '</th>
                                <th class="sco-cell">' . get_string('lastaccess', 'block_scormreport') . '</th>
                            </tr>
                        </thead>
                        <tbody>';

                foreach ($row['scos'] as $sco) {
                    $scobadge = block_scormreport_get_badge_info($sco['status']);
                    echo '<tr>
                        <td class="sco-cell-name">' . htmlspecialchars($sco['title']) . '</td>
                        <td class="sco-cell-mono">' . block_scormreport_hms($sco['seconds']) . '</td>
                        <td style="text-align:center;">' . (($sco['score'] !== false) ? $sco['score'] : '-') . '</td>
                        <td style="text-align:center;"><span class="badge ' . $scobadge['badge'] . '">' . $scobadge['label'] . '</span></td>
                        <td>' . $sco['firstaccess'] . '</td>
                        <td>' . $sco['lastaccess']  . '</td>
                    </tr>';
                }

                echo '</tbody></table></td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }
}



$PAGE->requires->js_call_amd('block_scormreport/report', 'init', [$jsparams]);

// Settings button at the very bottom (site admins only).
if (is_siteadmin()) {
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'blocksettingscormreport']);
    $gearicon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
    echo '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #dee2e6;">'
        . html_writer::link($settingsurl,
            '<span style="display:inline-flex;align-items:center;gap:6px;">' . $gearicon . ' ' . get_string('gotosettings', 'block_scormreport') . '</span>',
            ['class' => 'btn btn-outline-secondary'])
        . '</div>';
}

echo $OUTPUT->footer();
