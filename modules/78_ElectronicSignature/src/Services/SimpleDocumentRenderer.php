<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

/**
 * Renders a business record that has no PDF of its own (a purchase order, an employment contract) as a plain A4 document:
 * a title, a list of facts and an optional table. The captions arrive translated; this class holds no text.
 */
final class SimpleDocumentRenderer
{
    /**
     * @param  list<array{0: string, 1: string}>  $facts  label and value pairs
     * @param  list<array{title?: string, headers: list<string>, rows: list<list<string>>, widths?: list<float>}>  $tables
     * @param  list<string>  $paragraphs
     */
    public function render(string $title, array $facts, array $tables = [], array $paragraphs = [], string $footer = ''): string
    {
        PdfSignatureService::registerFpdf();

        $pdf = new \FPDF('P', 'pt', 'A4');
        $pdf->SetCreator('FatturaFacile');
        $pdf->SetTitle($this->latin1($title));
        $pdf->SetAutoPageBreak(true, 60);
        $pdf->AddPage();
        $left = 48.0;
        $width = 595.28 - 2 * $left;

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetXY($left, 56);
        $pdf->MultiCell($width, 20, $this->latin1($title));
        $pdf->Ln(10);

        foreach ($facts as [$label, $value]) {
            $pdf->SetX($left);
            $pdf->SetFont('Helvetica', 'B', 9.5);
            $pdf->Cell(150, 15, $this->latin1($label), 0, 0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->MultiCell($width - 150, 15, $this->latin1($value));
        }

        foreach ($tables as $table) {
            if ($table['rows'] === []) {
                continue;
            }

            $pdf->Ln(14);

            if (isset($table['title'])) {
                $pdf->SetX($left);
                $pdf->SetFont('Helvetica', 'B', 11);
                $pdf->Cell($width, 16, $this->latin1($table['title']), 0, 1);
            }

            $columns = count($table['headers']);
            $widths = $table['widths'] ?? array_fill(0, $columns, $width / $columns);
            $pdf->SetFont('Helvetica', 'B', 8.5);
            $pdf->SetFillColor(236, 240, 247);
            $pdf->SetX($left);

            foreach ($table['headers'] as $index => $header) {
                $pdf->Cell($widths[$index], 16, $this->latin1($header), 'B', 0, 'L', true);
            }

            $pdf->Ln();
            $pdf->SetFont('Helvetica', '', 8.5);

            foreach ($table['rows'] as $row) {
                $pdf->SetX($left);

                foreach ($row as $index => $cell) {
                    $limit = max(6, (int) floor($widths[$index] / 4.2));
                    $pdf->Cell($widths[$index], 14, $this->latin1(mb_strimwidth($cell, 0, $limit, '…')), 0, 0, 'L');
                }

                $pdf->Ln();
            }
        }

        foreach ($paragraphs as $paragraph) {
            $pdf->Ln(10);
            $pdf->SetX($left);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->MultiCell($width, 13, $this->latin1($paragraph));
        }

        if ($footer !== '') {
            $pdf->SetY(-48);
            $pdf->SetX($left);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell($width, 10, $this->latin1($footer), 0, 0, 'C');
        }

        return $pdf->Output('S');
    }

    private function latin1(string $text): string
    {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? (preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '') : $converted;
    }
}
