<?php
/**
 * Split PDF table cell text into chunks that fit a specified height.
 *
 * @param TCPDF $pdf PDF instance.
 * @param string $text Cell text.
 * @param float $width Cell width in millimetres.
 * @param float $maxheight Maximum fragment height in millimetres.
 * @return array Text fragments.
 */
function scormreport_pdf_split_cell($pdf, string $text, float $width, float $maxheight): array {
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
function scormreport_pdf_draw_paginated_row(
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
                $chunks = scormreport_pdf_split_cell(
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
