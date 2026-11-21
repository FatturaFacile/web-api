<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * Inaz Paghe fixed-width layout: record type "M"(1) matricola(8) period(6) voice(4) quantity(8, 2 decimals) vouchers(2)
 * reimbursement(10) fringe(10) INPS protocol(16), 65 columns.
 */
final class InazExportAdapter implements ConsulenteDelLavoroExportAdapter
{
    public const int RecordLength = 65;

    public function format(): ConsultantFormat
    {
        return ConsultantFormat::Inaz;
    }

    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile
    {
        $period = sprintf('%04d%02d', $year, $month);
        $lines = array_map(static fn (ExportRow $row): string => 'M'.FixedWidth::text($row->matricola, 8).$period.FixedWidth::text($row->voiceCode, 4)
            .FixedWidth::number($row->quantity, 8).FixedWidth::number((string) $row->mealVouchers, 2, 0).FixedWidth::number($row->reimbursement, 10)
            .FixedWidth::number($row->fringeBenefit, 10).FixedWidth::text($row->inpsProtocol ?? '', 16), $rows);

        return new ExportFile("INAZ_{$companyCode}_{$period}.dat", 'text/plain', implode("\r\n", $lines).($lines === [] ? '' : "\r\n"));
    }
}
