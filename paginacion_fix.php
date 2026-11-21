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
 * PDF table pagination helpers.
 *
 * @package    block_scormreport
 * @copyright  2026 Accros Business Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Split PDF table cell text into chunks that fit a specified height.
 *
 * @param TCPDF $pdf PDF instance.
 * @param string $text Cell text.
 * @param float $width Cell width in millimetres.
 * @param float $maxheight Maximum fragment height in millimetres.
 * @return array Text fragments.
 */
function block_scormreport_pdf_split_cell($pdf, string $text, float $width, float $maxheight): array {
    if ($text === '') {
        return [''];
    }

    $lineheight = $pdf->getStringHeight($width, 'Ag');
    if ($lineheight <= 0 || $maxheight < $lineheight) {
        throw new \RuntimeException('SCORM PDF: insufficient space for a text line');
    }

    $chunks = [];
    $remaining = $text;

    while ($remaining !== '') {
        $length = mb_strlen($remaining, 'UTF-8');
        $low = 1;
        $high = $length;
        $best = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = mb_substr($remaining, 0, $mid, 'UTF-8');

            if ($pdf->getStringHeight($width, $candidate) <= $maxheight) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        if ($best === 0) {
            throw new \RuntimeException('SCORM PDF: unable to split cell text');
        }

        $chunks[] = mb_substr($remaining, 0, $best, 'UTF-8');
        $remaining = mb_substr($remaining, $best, null, 'UTF-8');
    }

    return $chunks;
}

/**
 * Draw a table row over multiple pages when necessary.
 *
 * The callback recreates the report and table headers on each new page.
 *
 * @param TCPDF $pdf PDF instance.
 * @param array $cells Table cells.
 * @param callable $newpage Callback that adds a page and redraws headers.
 * @param bool $fill Alternate row background.
 * @param float $minh Minimum row height.
 */
function block_scormreport_pdf_draw_paginated_row(
    $pdf,
    array $cells,
    callable $newpage,
    bool $fill = false,
    float $minh = 6
): void {
    $pdf->SetFont('helvetica', '', 7);
    $remaining = array_map(
        static fn($cell) => (string)$cell['text'],
        $cells
    );

    $iterations = 0;
    while (true) {
        if (++$iterations > 10000) {
            throw new \RuntimeException('SCORM PDF: pagination limit exceeded');
        }

        $available = $pdf->getPageHeight() - 25 - $pdf->GetY();
        if ($available < max($minh, 8)) {
            $newpage();
            continue;
        }

        $maxheight = min($available - 5, 135);
        $fragments = [];
        $hasremaining = false;
        $rowheight = $minh;

        foreach ($cells as $index => $cell) {
            $text = $remaining[$index];

            if ($text === '') {
                $fragment = '';
                $rest = '';
            } else {
                $chunks = block_scormreport_pdf_split_cell(
                    $pdf,
                    $text,
                    (float)$cell['w'],
                    $maxheight
                );
                $fragment = array_shift($chunks);
                $rest = implode('', $chunks);
            }

            $fragments[] = $fragment;
            $remaining[$index] = $rest;
            $hasremaining = $hasremaining || $rest !== '';
            $rowheight = max(
                $rowheight,
                $pdf->getStringHeight((float)$cell['w'], $fragment)
            );
        }

        if ($rowheight > $available) {
            throw new \RuntimeException('SCORM PDF: row fragment exceeds page height');
        }

        $pdf->SetFillColor($fill ? 235 : 255, $fill ? 245 : 255, $fill ? 251 : 255);
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        foreach ($cells as $index => $cell) {
            $pdf->MultiCell(
                (float)$cell['w'],
                $rowheight,
                $fragments[$index],
                1,
                $cell['align'] ?? 'C',
                $fill,
                0,
                $x,
                $y
            );
            $x += (float)$cell['w'];
        }

        $pdf->SetXY($pdf->GetMargins()['left'], $y + $rowheight);

        if (!$hasremaining) {
            return;
        }

        $newpage();
        $pdf->SetFont('helvetica', '', 7);
    }
}
