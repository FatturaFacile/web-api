<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * TeamSystem Polyedro / Alyante standard layout: a header record (H) and one semicolon-separated movement record (M)
 * per line, decimals with a comma as in the Italian locale of the software.
 */
final class TeamSystemExportAdapter implements ConsulenteDelLavoroExportAdapter
{
    public function format(): ConsultantFormat
    {
        return ConsultantFormat::TeamSystem;
    }

    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile
    {
        $period = sprintf('%04d%02d', $year, $month);
        $lines = ["H;{$companyCode};{$period};".count($rows)];

        foreach ($rows as $row) {
            $lines[] = implode(';', [
                'M', $row->matricola, $row->voiceCode, str_replace('.', ',', $row->quantity), $row->unit, $row->mealVouchers,
                str_replace('.', ',', $row->reimbursement), str_replace('.', ',', $row->fringeBenefit), $row->inpsProtocol ?? '',
            ]);
        }

        return new ExportFile("TEAMSYSTEM_{$companyCode}_{$period}.txt", 'text/plain', implode("\r\n", $lines)."\r\n");
    }
}
