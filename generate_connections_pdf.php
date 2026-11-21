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
 * Generate a daily connection log PDF for a student (date, first/last time,
 * estimated active time and IP addresses used each day).
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

// License check.
require_once($CFG->dirroot . '/blocks/scormreport/classes/license.php');
if (!\block_scormreport\license::course_allowed($course)) {
    throw new moodle_exception('licenselocked', 'block_scormreport');
}

$targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
if (!is_enrolled($context, $targetuser, '', false) && !block_scormreport_is_course_tutor($context, $userid)) {
    throw new moodle_exception('notenrolled', 'block_scormreport');
}

$str = function($key, $a = null) { return get_string($key, 'block_scormreport', $a); };

// Get the daily connection data.
// Tutor activity also includes creating content (posts, feedback, grades).
$istutor = block_scormreport_is_course_tutor($context, $userid);
$daily = block_scormreport_get_daily_connections($courseid, $userid, $istutor ? ['c', 'r', 'u', 'd'] : ['r', 'u']);

// Logo.
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

// ── PDF setup ──
$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('Moodle - block_scormreport');
$pdf->SetTitle($str('connectionsreporttitle'));
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$pw = $pdf->getPageWidth() - 30; // usable width

// Header band.
$pdf->SetFillColor(0, 47, 75);
$pdf->Rect(0, 0, $pdf->getPageWidth(), 30, 'F');
if ($logopath && file_exists($logopath)) {
    $pdf->Image($logopath, 8, 4, 0, 22);
}
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(55, 7);
$pdf->Cell($pw - 40, 6, strtoupper($str('connectionsreporttitle')), 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(55, 16);
$pdf->Cell($pw - 40, 5, $str('generatedon', userdate(time(), '%d/%m/%Y %H:%M')), 0, 1, 'C');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetY(36);

// Student + course info.
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(40, 7, $str('fullname_label') . ':', 1, 0, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell($pw - 40, 7, fullname($targetuser), 1, 1, 'L');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(40, 7, $str('dni_label') . ':', 1, 0, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell($pw - 40, 7, $targetuser->idnumber ?: '-', 1, 1, 'L');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(40, 7, $str('coursename_label') . ':', 1, 0, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell($pw - 40, 7, $course->fullname, 1, 1, 'L');
$pdf->Ln(5);

// ── Daily connection table ──
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(0, 47, 75);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell($pw, 8, '  ' . $str('connectionsdaily'), 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Methodology note.
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(120, 120, 120);
$pdf->MultiCell($pw, 3.5, $str('connectionsmethodology'), 0, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

if (empty($daily)) {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell($pw, 8, $str('noconnections'), 0, 1, 'L');
} else {
    // Columns: date, first connection, activity, IPs.
    $cols = [28, 26, $pw - 118, 64];
    $headers = [$str('date'), $str('firstconn'), $str('activity'), $str('ipaddresses')];

    $drawheader = function() use ($pdf, $cols, $headers) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(0, 115, 170);
        $pdf->SetTextColor(255, 255, 255);
        for ($i = 0; $i < count($cols); $i++) {
            $pdf->Cell($cols[$i], 7, $headers[$i], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 8);
    };
    $drawheader();

    $fill = false;
    foreach ($daily as $d) {
        $ips = implode(', ', $d['ips']);
        $activity = $d['activity'];

        // Row height depends on the tallest wrapping cell (activity or IPs).
        $acth = $pdf->getStringHeight($cols[2], $activity);
        $iph  = $pdf->getStringHeight($cols[3], $ips);
        $rowh = max(6, $acth, $iph);
        if ($pdf->GetY() + $rowh > $pdf->getPageHeight() - 20) {
            $pdf->AddPage();
            $drawheader();
        }

        $pdf->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 252 : 255);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->MultiCell($cols[0], $rowh, userdate($d['first'], '%d/%m/%Y'), 1, 'C', $fill, 0, $x, $y, true, 0, false, true, $rowh, 'M');
        $x += $cols[0];
        $pdf->MultiCell($cols[1], $rowh, userdate($d['first'], '%H:%M'), 1, 'C', $fill, 0, $x, $y, true, 0, false, true, $rowh, 'M');
        $x += $cols[1];
        $pdf->MultiCell($cols[2], $rowh, $activity, 1, 'L', $fill, 0, $x, $y, true, 0, false, true, $rowh, 'M');
        $x += $cols[2];
        $pdf->MultiCell($cols[3], $rowh, $ips, 1, 'L', $fill, 1, $x, $y, true, 0, false, true, $rowh, 'M');

        $fill = !$fill;
    }
}

// Footer note.
$pdf->Ln(8);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(150, 150, 150);
$pdf->MultiCell($pw, 4, $str('evidencefooter'), 0, 'L');

$filename = clean_filename('conexiones_' . ($targetuser->idnumber ?: $userid) . '_' . $course->shortname . '_' . date('Ymd') . '.pdf');
$pdf->Output($filename, 'D');
