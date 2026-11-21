<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * Zucchetti Paghe Web positional ASCII (one 80-column record per line):
 * company(6) period(6 AAAAMM) matricola(10) voice(6) quantity(9, 2 implied decimals) unit(1) vouchers(3) reimbursement(11) fringe(11) INPS protocol(17).
 */
final class ZucchettiExportAdapter implements ConsulenteDelLavoroExportAdapter
{
    public const int RecordLength = 80;

    public function format(): ConsultantFormat
    {
        return ConsultantFormat::Zucchetti;
    }

    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile
    {
        $period = sprintf('%04d%02d', $year, $month);
        $lines = array_map(static fn (ExportRow $row): string => substr(
            FixedWidth::text($companyCode, 6).$period.FixedWidth::text($row->matricola, 10).FixedWidth::text($row->voiceCode, 6)
            .FixedWidth::number($row->quantity, 9).FixedWidth::text($row->unit, 1).FixedWidth::number((string) $row->mealVouchers, 3, 0)
            .FixedWidth::number($row->reimbursement, 11).FixedWidth::number($row->fringeBenefit, 11).FixedWidth::text($row->inpsProtocol ?? '', 17),
            0,
            self::RecordLength,
        ), $rows);

        return new ExportFile("ZUCCHETTI_{$companyCode}_{$period}.txt", 'text/plain', implode("\r\n", $lines).($lines === [] ? '' : "\r\n"));
    }
}
