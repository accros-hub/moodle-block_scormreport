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
 * PDF report generator.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$userid   = optional_param('userid',   0, PARAM_INT);

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('block/scormreport:export', $context);

// License check: only courses covered by the license.
if (!\block_scormreport\license::course_allowed($course)) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

require_once($CFG->libdir . '/pdflib.php');
require_once($CFG->dirroot . '/blocks/scormreport/lib_report.php');

$str = function($key, $a = null) { return get_string($key, 'block_scormreport', $a); };

// ─── Data ────────────────────────────────────────────────────────────────────
$reportdata = block_scormreport_get_data($courseid, $userid, 0, $context, $course, $DB, $CFG);
$students    = $reportdata['students'];
$globaldata  = $reportdata['globaldata'];
$detaildata  = $reportdata['detaildata'];
$scorms      = $reportdata['scorms'];
$coursehours = $reportdata['coursehours'];

$startdate = !empty($course->startdate) ? userdate($course->startdate, '%d/%m/%Y') : '-';
$enddate   = !empty($course->enddate)   ? userdate($course->enddate,   '%d/%m/%Y') : '-';

// Logo (compatible with local and remote S3 storage)
$logopath = '';
$fs = get_file_storage();
$syscontext = context_system::instance();
$logofiles = $fs->get_area_files($syscontext->id, 'core_admin', 'logo', false, 'id DESC', false);
if (!empty($logofiles)) {
    $logofile = reset($logofiles);
    $tmpdir = make_temp_directory('block_scormreport');
    $tmpfile = $tmpdir . '/logo_' . $logofile->get_contenthash();
    $logofile->copy_content_to($tmpfile);
    $logopath = $tmpfile;
}

// Configurable threshold
$apt_threshold = get_config('block_scormreport', 'apt_threshold');
$apt_threshold = ($apt_threshold === false || $apt_threshold === '') ? 75 : (int)$apt_threshold;
$apt_threshold = max(0, min(100, $apt_threshold));
$deadline_tolerance = get_config('block_scormreport', 'deadline_tolerance');
$deadline_tolerance = ($deadline_tolerance === false || $deadline_tolerance === '') ? 15 : (int)$deadline_tolerance;
$deadline_tolerance = max(0, min(100, $deadline_tolerance));

// ── Column preferences (same selection as the on-screen report) ──
// Optional columns the user can show/hide. "student" is always shown.
$pdf_optional_columns = ['username', 'email', 'dni', 'company', 'timescorm',
    'pcttimecourse', 'score', 'pctvistos', 'pctsuperados', 'seen', 'passed',
    'attempts_total', 'firstaccess', 'lastaccess', 'progresscolumn', 'deadlinestatus'];
$pdf_default_columns = ['company', 'timescorm', 'pcttimecourse', 'score',
    'pctsuperados', 'firstaccess', 'lastaccess', 'progresscolumn', 'deadlinestatus'];
$pdf_colpref = get_user_preferences('block_scormreport_columns', null);
if ($pdf_colpref === null) {
    $pdf_visible = $pdf_default_columns;
} else {
    $pdf_visible = ($pdf_colpref === '') ? [] : explode(',', $pdf_colpref);
}
$pdf_show = function($key) use ($pdf_visible) {
    return in_array($key, $pdf_visible);
};

// Column definitions for the summary table: label, relative width, and value.
$pdf_coldefs = [
    'username'       => ['w' => 22, 'label' => get_string('username', 'block_scormreport'),      'val' => fn($r) => $r['username'] ?? ''],
    'email'          => ['w' => 40, 'label' => get_string('email', 'block_scormreport'),         'val' => fn($r) => $r['email'] ?? ''],
    'dni'            => ['w' => 22, 'label' => get_string('dni', 'block_scormreport'),           'val' => fn($r) => $r['dni'] ?? ''],
    'company'        => ['w' => 35, 'label' => get_string('company', 'block_scormreport'),       'val' => fn($r) => $r['company'] ?? ''],
    'timescorm'      => ['w' => 20, 'label' => get_string('timescorm', 'block_scormreport'),     'val' => fn($r) => $r['timescorm']],
    'pcttimecourse'  => ['w' => 18, 'label' => get_string('pcttimecourse', 'block_scormreport'), 'val' => fn($r) => $r['pcttotal']],
    'score'          => ['w' => 15, 'label' => get_string('score', 'block_scormreport'),         'val' => fn($r) => $r['score']],
    'pctvistos'      => ['w' => 18, 'label' => get_string('pctvistos', 'block_scormreport'),     'val' => fn($r) => $r['pctvistos']],
    'pctsuperados'   => ['w' => 18, 'label' => get_string('pctsuperados', 'block_scormreport'),  'val' => fn($r) => $r['pctsuperados']],
    'seen'           => ['w' => 16, 'label' => get_string('topicsseen', 'block_scormreport'),    'val' => fn($r) => $r['seen'] ?? 0],
    'passed'         => ['w' => 16, 'label' => get_string('topicspassed', 'block_scormreport'),  'val' => fn($r) => $r['passed'] ?? 0],
    'attempts_total' => ['w' => 16, 'label' => get_string('attemptstotal', 'block_scormreport'), 'val' => fn($r) => $r['attempts_total'] ?? 0],
    'firstaccess'    => ['w' => 28, 'label' => get_string('firstaccess', 'block_scormreport'),   'val' => fn($r) => $r['firstaccess']],
    'lastaccess'     => ['w' => 28, 'label' => get_string('lastaccess', 'block_scormreport'),    'val' => fn($r) => $r['lastaccess']],
    'progresscolumn' => ['w' => 22, 'label' => get_string('progresscolumn', 'block_scormreport'),'val' => null], // special (apto badge)
    'deadlinestatus' => ['w' => 22, 'label' => get_string('deadlinestatus', 'block_scormreport'),'val' => null], // special (deadline)
];

require_once __DIR__ . "/paginacion_fix.php";

// ─── PDF setup ───────────────────────────────────────────────────────────────
$pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('Moodle - ' . $str('pluginname'));
$pdf->SetTitle($str('pdftitle', $course->fullname));
$pdf->SetMargins(10, 35, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->setFooterFont(['helvetica', '', 8]);
$pdf->setFooterData([0, 73, 110], [255, 255, 255]);

// ─── Header function ─────────────────────────────────────────────────────────
function block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str) {
    $pw = $pdf->getPageWidth();

    $pdf->SetFillColor(0, 73, 110);
    $pdf->Rect(0, 0, $pw, 28, 'F');

    if ($logopath && file_exists($logopath)) {
        $pdf->Image($logopath, 5, 3, 0, 22, '', '', '', false, 150);
    }

    // Title to the right of the logo (starts at X=50 to leave room for the logo)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(50, 5);
    $titletext = strtoupper($str('reporttitle')) . ': ' . strtoupper($course->fullname);
    $pdf->MultiCell($pw - 60, 5, $titletext, 0, 'C', false, 1, 50, 5);

    $pdf->SetFont('helvetica', '', 9);
    $meta = $startdate . ' — ' . $enddate;
    if ($coursehours) $meta .= '  |  ' . $coursehours . 'h';
    $pdf->SetXY(10, 15);
    $pdf->Cell($pw - 20, 6, $meta, 0, 1, 'C');

    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetXY(10, 21);
    $pdf->Cell($pw - 20, 5, $str('generatedon', userdate(time(), '%d/%m/%Y %H:%M')), 0, 1, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(32);
}

// ─── Table header function ───────────────────────────────────────────────────
function block_scormreport_draw_table_header($pdf, $isdetail, $str) {
    $pdf->SetFillColor(0, 73, 110);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);

    if ($isdetail) {
        $widths = [50, 55, 25, 15, 30, 35, 37];
        $labels = [$str('student'), $str('scormname'), $str('timescorm'),
                   $str('score'), $str('status'), $str('firstaccess'), $str('lastaccess')];
    } else {
        $widths = [45, 20, 15, 20, 20, 18, 22, 33, 34];
        $labels = [$str('student'), $str('timescorm'), $str('score'),
                   $str('pctvistos'), $str('pctsuperados'), $str('apt'),
                   $str('deadlinestatus'), $str('firstaccess'), $str('lastaccess')];
    }
    // Two-line header: MultiCell with fixed height and vertical centring.
    $h = 10;
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    for ($i = 0; $i < count($widths); $i++) {
        $pdf->MultiCell($widths[$i], $h, $labels[$i], 1, 'C', true, 0, $x, $y, true, 0, false, true, $h, 'M');
        $x += $widths[$i];
    }
    $pdf->Ln($h);
    $pdf->SetTextColor(0, 0, 0);
}

/**
 * Draw a table row with text wrapping support.
 * @param TCPDF $pdf
 * @param array $cells [['w' => width, 'text' => text, 'align' => 'L|C|R'], ...]
 * @param bool $fill Alternate row fill
 * @param float $minh Minimum row height
 */
function block_scormreport_draw_row($pdf, array $cells, bool $fill = false, float $minh = 6) {
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetFillColor($fill ? 235 : 255, $fill ? 245 : 255, $fill ? 251 : 255);

    // Calculate max height needed
    $maxh = $minh;
    foreach ($cells as $c) {
        $h = $pdf->getStringHeight($c['w'], $c['text']);
        if ($h > $maxh) $maxh = $h;
    }

    // Check page break
    if ($pdf->GetY() + $maxh > $pdf->getPageHeight() - 25) {
        return false; // Signal caller to add page
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    foreach ($cells as $c) {
        $pdf->MultiCell($c['w'], $maxh, $c['text'], 1, $c['align'] ?? 'C', $fill, 0, $x, $y);
        $x += $c['w'];
    }
    $pdf->Ln($maxh);
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// Individual PDF
// ═══════════════════════════════════════════════════════════════════════════
if ($userid > 0) {
    $targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

    // Verify the student is enrolled (active or inactive)
    if (!is_enrolled($context, $targetuser, '', false)) {
        throw new moodle_exception('notenrolled', 'block_scormreport');
    }

    $userdetail = array_filter($detaildata, fn($r) => $r['userid'] == $userid);

    $pdf->AddPage();
    block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(235, 245, 251);
    $a = new stdClass();
    $a->name = fullname($targetuser);
    $a->email = $targetuser->email;
    $pdf->Cell(0, 8, $str('studentlabel', $a), 1, 1, 'L', true);
    $pdf->Ln(3);

    block_scormreport_draw_table_header($pdf, true, $str);

    $fill = false;
    foreach ($userdetail as $row) {
        $badgeinfo = block_scormreport_get_badge_info($row['status']);
        block_scormreport_pdf_draw_paginated_row($pdf, [
            ["w" => 50, "text" => fullname($targetuser), "align" => "L"],
            ["w" => 55, "text" => $row["scormname"], "align" => "L"],
            ["w" => 25, "text" => $row["timescorm"], "align" => "C"],
            ["w" => 15, "text" => $row["score"], "align" => "C"],
            ["w" => 30, "text" => $badgeinfo["label"], "align" => "C"],
            ["w" => 35, "text" => $row["firstaccess"], "align" => "C"],
            ["w" => 37, "text" => $row["lastaccess"], "align" => "C"],
        ], function() use ($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str) {
            $pdf->AddPage();
            block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);
            block_scormreport_draw_table_header($pdf, true, $str);
        }, $fill);
        $fill = !$fill;
    }

} else {
    // ═══════════════════════════════════════════════════════════════════════
    // Full PDF
    // ═══════════════════════════════════════════════════════════════════════
    $pdf->AddPage();
    block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(235, 245, 251);
    $pdf->Cell(60, 7, $str('totalstudents') . ': ' . count($students), 1, 0, 'C', true);
    $pdf->Cell(60, 7, $str('totalscorms') . ': '   . count($scorms),   1, 0, 'C', true);
    $pdf->Cell(0,  7, '',                                               1, 1, 'C', true);
    $pdf->Ln(3);

    // Build the active column list: "student" is always first, then the
    // optional columns selected by the user (same as the on-screen report).
    $activecols = [];
    foreach ($pdf_optional_columns as $ck) {
        if ($pdf_show($ck)) $activecols[] = $ck;
    }

    // Compute widths: student fixed at 45mm; the rest scaled to fit the page.
    $usable = $pdf->getPageWidth() - 20; // left+right margins (10 each)
    $studentw = 45;
    $rawsum = 0;
    foreach ($activecols as $ck) $rawsum += $pdf_coldefs[$ck]['w'];
    $avail = $usable - $studentw;
    $scale = ($rawsum > 0 && $rawsum > $avail) ? ($avail / $rawsum) : 1;

    // Header drawing closure (reused on page breaks).
    $draw_summary_header = function() use ($pdf, $str, $activecols, $pdf_coldefs, $studentw, $scale) {
        $cells = [['w' => $studentw, 'text' => $str('student'), 'align' => 'C']];
        foreach ($activecols as $ck) {
            $cells[] = ['w' => $pdf_coldefs[$ck]['w'] * $scale, 'text' => $pdf_coldefs[$ck]['label'], 'align' => 'C'];
        }
        $pdf->SetFillColor(0, 73, 110);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 7);
        $h = 10;
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($cells as $c) {
            $pdf->MultiCell($c['w'], $h, $c['text'], 1, 'C', true, 0, $x, $y, true, 0, false, true, $h, 'M');
            $x += $c['w'];
        }
        $pdf->Ln($h);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 7);
    };

    $draw_summary_header();

    $fill = false;
    foreach ($globaldata as $row) {
        $pspct = (float)rtrim($row['pctsuperados'], '%');
        $apto  = $pspct >= $apt_threshold ? $str('apt') : $str('notapt');
        $dlstatus = block_scormreport_calc_deadline_status($pspct, $course->startdate ?? 0, $course->enddate ?? 0, $deadline_tolerance);

        // Build the row cells matching the active columns.
        $cells = [['w' => $studentw, 'text' => $row['studentname'], 'align' => 'L']];
        foreach ($activecols as $ck) {
            $w = $pdf_coldefs[$ck]['w'] * $scale;
            if ($ck === 'progresscolumn') {
                $text = $apto;
            } else if ($ck === 'deadlinestatus') {
                $text = $dlstatus['label'];
            } else {
                $text = (string)($pdf_coldefs[$ck]['val'])($row);
            }
            $cells[] = ['w' => $w, 'text' => $text, 'align' => ($ck === 'email' || $ck === 'company') ? 'L' : 'C'];
        }

        block_scormreport_pdf_draw_paginated_row($pdf, $cells, function() use ($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str, $draw_summary_header) {
            $pdf->AddPage();
            block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);
            $draw_summary_header();
        }, $fill);
        $fill = !$fill;
    }
}

// ── SCO matrix (student x SCORM) ──
if (!$userid) {
    $pdf->AddPage();
    block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, $str('topicscolumn') . ' — ' . $str('reporttitle'), 0, 1, 'L');
    $pdf->Ln(2);

    // Matrix header
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(0, 115, 170);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(40, 6, $str('student'),     1, 0, 'C', true);
    $pdf->Cell(55, 6, $str('scormname'),   1, 0, 'C', true);
    $pdf->Cell(20, 6, $str('timescorm'),   1, 0, 'C', true);
    $pdf->Cell(15, 6, $str('score'),       1, 0, 'C', true);
    $pdf->Cell(25, 6, $str('status'),      1, 0, 'C', true);
    $pdf->Cell(30, 6, $str('firstaccess'), 1, 0, 'C', true);
    $pdf->Cell(32, 6, $str('lastaccess'),  1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $fill = false;
    foreach ($globaldata as $row) {
        if (empty($row['scormdetails'])) continue;
        foreach ($row['scormdetails'] as $sd) {
            $badgeinfo = block_scormreport_get_badge_info($sd['status']);
            block_scormreport_pdf_draw_paginated_row($pdf, [
                ["w" => 40, "text" => $row["studentname"], "align" => "L"],
                ["w" => 55, "text" => $sd["scormname"], "align" => "L"],
                ["w" => 20, "text" => $sd["timescorm"], "align" => "C"],
                ["w" => 15, "text" => $sd["score"], "align" => "C"],
                ["w" => 25, "text" => $badgeinfo["label"], "align" => "C"],
                ["w" => 30, "text" => $sd["firstaccess"], "align" => "C"],
                ["w" => 32, "text" => $sd["lastaccess"], "align" => "C"],
            ], function() use ($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str) {
                $pdf->AddPage();
                block_scormreport_draw_header($pdf, $course, $startdate, $enddate, $coursehours, $logopath, $str);
                $pdf->SetFont("helvetica", "B", 7);
                $pdf->SetFillColor(0, 115, 170);
                $pdf->SetTextColor(255, 255, 255);
                foreach ([[40,"student"],[55,"scormname"],[20,"timescorm"],[15,"score"],[25,"status"],[30,"firstaccess"],[32,"lastaccess"]] as $col) {
                    $pdf->Cell($col[0], 6, $str($col[1]), 1, 0, "C", true);
                }
                $pdf->Ln(6);
                $pdf->SetTextColor(0, 0, 0);
            }, $fill, 5);
            $fill = !$fill;
        }
    }
}

// ─── Output ──────────────────────────────────────────────────────────────────
$filename = clean_filename('report_' . $course->shortname . '_' . date('Ymd_His') . '.pdf');
$pdf->Output($filename, 'D');
