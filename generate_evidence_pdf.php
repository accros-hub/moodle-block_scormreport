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
 * Generate Training Compliance evidence PDF for a student.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib_report.php');
require_once($CFG->libdir . '/pdflib.php');

$courseid = required_param('courseid', PARAM_INT);
$userid   = required_param('userid', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context  = context_course::instance($courseid);
require_login($course);
require_capability('block/scormreport:export', $context);

// License check: only courses covered by the license.
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');
if (!\block_scormreport\license::course_allowed($course)) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

$targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
if (!is_enrolled($context, $targetuser, '', false)) {
    throw new moodle_exception('notenrolled', 'block_scormreport');
}

$apt_threshold = get_config('block_scormreport', 'apt_threshold');
$apt_threshold = ($apt_threshold === false || $apt_threshold === '') ? 75 : (int)$apt_threshold;
$apt_threshold = max(0, min(100, $apt_threshold));
$str = function($key, $a = null) { return get_string($key, 'block_scormreport', $a); };

// Get report data
$reportdata = block_scormreport_get_data($courseid, $userid, 0, $context, $course, $DB, $CFG);
$globaldata = $reportdata['globaldata'];
$detaildata = $reportdata['detaildata'];
$coursehours = $reportdata['coursehours'];
$totalscorms = $reportdata['totalscorms'];

// Find this student's data
$studentdata = null;
foreach ($globaldata as $row) {
    if ($row['userid'] == $userid) {
        $studentdata = $row;
        break;
    }
}

if (!$studentdata) {
    throw new moodle_exception('nodata', 'block_scormreport');
}

// Get connection data
$connections = block_scormreport_get_connections($courseid, [$userid]);
$conn = $connections[$userid] ?? ['sessions' => 0, 'total_seconds' => 0, 'first_login' => 0, 'last_login' => 0, 'dates' => []];

// Logo
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

// Course dates
$startdate = $course->startdate ? userdate($course->startdate, '%d/%m/%Y') : '-';
$enddate = $course->enddate ? userdate($course->enddate, '%d/%m/%Y') : '-';

/**
 * Draw a two-column label:value row where both cells wrap and the row height
 * adapts to the tallest. Label in bold, value in normal.
 */
function block_scormreport_draw_label_value($pdf, $label_w, $value_w, $label, $value, $minh = 7) {
    $pdf->SetFont('helvetica', 'B', 10);
    $hl = $pdf->getStringHeight($label_w, (string)$label);
    $pdf->SetFont('helvetica', '', 10);
    $hv = $pdf->getStringHeight($value_w, (string)$value);
    $maxh = max($minh, $hl, $hv);

    if ($pdf->GetY() + $maxh > $pdf->getPageHeight() - 20) {
        $pdf->AddPage();
    }
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->MultiCell($label_w, $maxh, (string)$label, 1, 'L', false, 0, $x, $y);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell($value_w, $maxh, (string)$value, 1, 'L', false, 1, $x + $label_w, $y);
}

/**
 * Draw a table header row with wrapping cells (fixed height, small bold font).
 */
function block_scormreport_draw_header_row($pdf, array $widths, array $labels, $h = 8) {
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(230, 240, 250);
    $pdf->SetTextColor(0, 0, 0);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    for ($i = 0; $i < count($widths); $i++) {
        $pdf->MultiCell($widths[$i], $h, $labels[$i], 1, 'C', true, 0, $x, $y, true, 0, false, true, $h, 'M');
        $x += $widths[$i];
    }
    $pdf->Ln($h);
}

// ── PDF Setup ──
$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('Moodle - block_scormreport');

/**
 * Draw a table row where every cell wraps its text and the row height
 * adapts to the tallest cell. No text is truncated.
 *
 * @param TCPDF $pdf
 * @param array $cells [['w'=>width, 'text'=>string, 'align'=>'L|C|R'], ...]
 * @param bool $fill
 * @param float $minh
 */
function block_scormreport_draw_wrapped_row($pdf, array $cells, bool $fill = false, float $minh = 5) {
    // Compute the height needed by the tallest cell.
    $maxh = $minh;
    foreach ($cells as $c) {
        $h = $pdf->getStringHeight($c['w'], (string)$c['text']);
        if ($h > $maxh) $maxh = $h;
    }
    // Page break if needed.
    if ($pdf->GetY() + $maxh > $pdf->getPageHeight() - 20) {
        return false;
    }
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    foreach ($cells as $c) {
        $pdf->MultiCell($c['w'], $maxh, (string)$c['text'], 1, $c['align'] ?? 'L', $fill, 0, $x, $y);
        $x += $c['w'];
    }
    $pdf->Ln($maxh);
    return true;
}

$pdf->SetTitle($str('evidencetitle', $course->fullname));
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 25);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$pw = $pdf->getPageWidth() - 30; // 165mm usable

// ── Header with logo ──
$pdf->SetFillColor(0, 73, 110);
$pdf->Rect(0, 0, $pdf->getPageWidth(), 30, 'F');

if ($logopath && file_exists($logopath)) {
    $pdf->Image($logopath, 8, 4, 0, 22);
}

$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(55, 5);
$pdf->MultiCell($pw - 40, 6, strtoupper($str('evidencetitle', '')), 0, 'C', false, 1, 55, 5);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(55, 17);
$pdf->Cell($pw - 40, 5, $str('generatedon', userdate(time(), '%d/%m/%Y %H:%M')), 0, 1, 'C');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetY(35);

// ── Section 1: Student data ──
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(0, 73, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($pw, 8, '  1. ' . $str('studentdata'), 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 10);
$row_h = 7;
$label_w = 62;
$value_w = $pw - $label_w;

$fields = [
    [$str('fullname_label'), fullname($targetuser)],
    [$str('dni_label'), $targetuser->idnumber ?: '-'],
    [$str('email_label'), $targetuser->email],
];
foreach ($fields as $f) {
    $pdf->SetFont('helvetica', '', 10);
    block_scormreport_draw_label_value($pdf, $label_w, $value_w, $f[0] . ':', $f[1], $row_h);
}
$pdf->Ln(5);

// ── Section 2: Course data ──
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(0, 73, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($pw, 8, '  2. ' . $str('coursedata'), 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);

$pspct = (float)rtrim($studentdata['pctsuperados'], '%');
$apto = $pspct >= $apt_threshold ? $str('apt') : $str('notapt');

$cfields = [
    [$str('coursename_label'), $course->fullname],
    [$str('coursedates_label'), $startdate . ' — ' . $enddate],
    [$str('coursehours_label'), ($coursehours ?: '-') . ' h'],
    [$str('scormtotal_label'), $totalscorms . ' ' . $str('activities')],
    [$str('scormtime_label'), $studentdata['timescorm']],
    [$str('connectionscount_label'), $conn['sessions'] . ' ' . strtolower($str('attempts_count'))],
    [$str('scoreavg_label'), $studentdata['score']],
    [$str('pctpassed_label'), $studentdata['pctsuperados']],
    [$str('result_label'), $apto],
];
foreach ($cfields as $f) {
    block_scormreport_draw_label_value($pdf, $label_w, $value_w, $f[0] . ':', $f[1], $row_h);
}
$pdf->Ln(5);

// ── Section 3: Topic breakdown ──
if ($pdf->GetY() > 200) { $pdf->AddPage(); }

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(0, 73, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($pw, 8, '  3. ' . $str('topicbreakdown'), 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);

// Header
$cols = [50, 22, 20, 25, 24, 24];
$headers_t = [$str('scormname'), $str('timescorm'), $str('score'), $str('status'), $str('firstaccess'), $str('lastaccess')];
block_scormreport_draw_header_row($pdf, $cols, $headers_t);

// Rows
$pdf->SetFont('helvetica', '', 7);
$fill = false;
$userdetails = !empty($studentdata['scormdetails']) ? $studentdata['scormdetails'] : [];
foreach ($userdetails as $sd) {
    $badgeinfo = block_scormreport_get_badge_info($sd['status']);
    $rowcells = [
        ['w' => $cols[0], 'text' => $sd['scormname'],   'align' => 'L'],
        ['w' => $cols[1], 'text' => $sd['timescorm'],   'align' => 'C'],
        ['w' => $cols[2], 'text' => $sd['score'],       'align' => 'C'],
        ['w' => $cols[3], 'text' => $badgeinfo['label'],'align' => 'C'],
        ['w' => $cols[4], 'text' => $sd['firstaccess'], 'align' => 'C'],
        ['w' => $cols[5], 'text' => $sd['lastaccess'],  'align' => 'C'],
    ];
    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 252 : 255);
    if (!block_scormreport_draw_wrapped_row($pdf, $rowcells, $fill)) {
        $pdf->AddPage();
        block_scormreport_draw_header_row($pdf, $cols, $headers_t);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 252 : 255);
        block_scormreport_draw_wrapped_row($pdf, $rowcells, $fill);
    }
    $fill = !$fill;
}
$pdf->Ln(5);

// ── Section 3b: Evaluation detail (test interactions) ──
$hasany = false;
foreach ($userdetails as $sd) {
    if (!empty($sd['interactions'])) { $hasany = true; break; }
}
if ($hasany) {
    if ($pdf->GetY() > 200) { $pdf->AddPage(); }
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(0, 73, 110);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($pw, 8, '  ' . $str('evaluationdetail'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    foreach ($userdetails as $sd) {
        if (empty($sd['interactions'])) continue;

        // SCORM name subheading.
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($pw, 6, mb_strimwidth($sd['scormname'], 0, 90, '…'), 0, 1, 'L');

        // Header row.
        $icols = [22, 111, 28];
        $iheaders = [$str('questionno'), $str('markedanswer'), $str('answerresult')];
        block_scormreport_draw_header_row($pdf, $icols, $iheaders);

        $pdf->SetFont('helvetica', '', 7);
        $qn = 0;
        $ifill = false;
        foreach ($sd['interactions'] as $inter) {
            $qn++;
            $res = strtolower(trim($inter['result']));
            if ($res === 'correct') {
                $reslabel = $str('answercorrect');
            } else if ($res === 'incorrect' || $res === 'wrong') {
                $reslabel = $str('answerincorrect');
            } else {
                $reslabel = $inter['result'];
            }
            $intercells = [
                ['w' => $icols[0], 'text' => $qn, 'align' => 'C'],
                ['w' => $icols[1], 'text' => block_scormreport_display_response($inter), 'align' => 'L'],
                ['w' => $icols[2], 'text' => $reslabel, 'align' => 'C'],
            ];
            $pdf->SetFillColor($ifill ? 245 : 255, $ifill ? 248 : 255, $ifill ? 252 : 255);
            if (!block_scormreport_draw_wrapped_row($pdf, $intercells, $ifill)) {
                $pdf->AddPage();
                block_scormreport_draw_header_row($pdf, $icols, $iheaders);
                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetFillColor($ifill ? 245 : 255, $ifill ? 248 : 255, $ifill ? 252 : 255);
                block_scormreport_draw_wrapped_row($pdf, $intercells, $ifill);
            }
            $ifill = !$ifill;
        }
        $pdf->Ln(4);
    }
}

// ── Section 4: Signature ──
if ($pdf->GetY() > 240) { $pdf->AddPage(); }

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(0, 73, 110);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($pw, 8, '  4. ' . $str('tutorsignature'), 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell($pw, 7, $str('signedby') . ': ' . fullname($USER), 0, 1, 'L');
$pdf->Cell($pw, 7, $str('signdate') . ': ' . userdate(time(), '%d/%m/%Y'), 0, 1, 'L');
$pdf->Ln(15);

// Signature line
$pdf->Cell(80, 0, '', 'T', 0, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(80, 5, $str('signatureplace'), 0, 1, 'C');

// ── Footer note ──
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(150, 150, 150);
$pdf->MultiCell($pw, 4, $str('evidencefooter'), 0, 'L');

// Output
$filename = clean_filename('evidencia_' . $targetuser->idnumber . '_' . $course->shortname . '_' . date('Ymd') . '.pdf');
$pdf->Output($filename, 'D');
