<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

use Modules\ItalianPayroll\Domain\Enums\ConsultantFormat;

/**
 * Fallback: a semicolon CSV with a header, UTF-8 with BOM so that Excel opens it with the right encoding.
 */
final class GenericCsvExportAdapter implements ConsulenteDelLavoroExportAdapter
{
    public function format(): ConsultantFormat
    {
        return ConsultantFormat::GenericCsv;
    }

    public function render(int $year, int $month, string $companyCode, array $rows): ExportFile
    {
        $lines = [$this->line(['anno', 'mese', 'matricola', 'causale', 'voce', 'quantita', 'unita', 'buoni_pasto', 'rimborso', 'fringe_benefit', 'protocollo_inps'])];

        foreach ($rows as $row) {
            $lines[] = $this->line([
                (string) $year, sprintf('%02d', $month), $row->matricola, $row->causal, $row->voiceCode, $row->quantity, $row->unit,
                (string) $row->mealVouchers, $row->reimbursement, $row->fringeBenefit, $row->inpsProtocol ?? '',
            ]);
        }

        return new ExportFile(sprintf('LUL_%s_%04d%02d.csv', $companyCode, $year, $month), 'text/csv', "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n");
    }

    /**
     * @param  list<string>  $fields
     */
    private function line(array $fields): string
    {
        return implode(';', array_map(static fn (string $field): string => preg_match('/[;"\r\n]/', $field) === 1
            ? '"'.str_replace('"', '""', $field).'"'
            : $field, $fields));
    }
}
