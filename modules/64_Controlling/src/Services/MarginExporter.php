<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and XLSX export of the margin explorer. Text cells are neutralised against formula injection.
 */
final class MarginExporter
{
    /**
     * @param  list<list<string>>  $rows
     */
    public function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(static function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, MultidimensionalMarginService::exportHeader(), ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn (string $cell): string => CsvFormulaNeutralizer::neutralize($cell), $row), ',', '"', '\\');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    public function xlsx(array $rows, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'margin_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Margins');
        $writer->addRow(Row::fromValuesWithStyle(MultidimensionalMarginService::exportHeader(), (new Style)->withFontBold(true)));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(static fn (string $cell): string|float => is_numeric($cell) ? (float) $cell : CsvFormulaNeutralizer::neutralize($cell), $row)));
        }
        $writer->close();

        return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
