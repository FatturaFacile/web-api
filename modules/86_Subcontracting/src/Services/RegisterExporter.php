<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Tenant;
use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Renders the statutory register of the goods in conto lavoro (rows of SubcontractingReportService::registerRows) as CSV, .xlsx or
 * PDF, with the headers and labels of the requested locale.
 */
final class RegisterExporter
{
    private const array Columns = [
        'entry_date', 'direction', 'movement_type', 'order_number', 'partner_name', 'sku', 'product_name', 'dispatched_qty',
        'consumed_in_returned_goods_qty', 'scrap_reported_by_subcontractor_qty', 'returned_unused_qty', 'remaining_at_subcontractor_qty',
        'ddt_number', 'ddt_date', 'transport_reason', 'statutory_return_deadline', 'deadline_status', 'customs_declaration_id', 'valuation_amount',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function csv(array $rows, string $locale): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('A temporary stream for the register could not be opened.');
        }

        fputcsv($handle, $this->headers($locale), ';', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $this->cells($row, $locale, true), ';', '"', '\\');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$content;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function xlsx(array $rows, string $locale, ?string $tenantId = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sctreg');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the register could not be created.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName(mb_substr($this->label('sheet', $locale), 0, 31));
            $writer->addRow(Row::fromValues([$this->label('title', $locale), CsvFormulaNeutralizer::neutralize($this->company($tenantId))]));
            $writer->addRow(Row::fromValues($this->headers($locale)));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($this->cells($row, $locale, true)));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function pdf(array $rows, string $locale, ?string $tenantId = null): string
    {
        $head = '';
        foreach ($this->headers($locale) as $header) {
            $head .= '<th>'.e($header).'</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $class = in_array($row['deadline_status'] ?? '', ['expired', 'warning'], true) ? ' class="'.$row['deadline_status'].'"' : '';
            $body .= '<tr'.$class.'>';
            foreach ($this->cells($row, $locale, false) as $cell) {
                $body .= '<td>'.e((string) $cell).'</td>';
            }
            $body .= '</tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:6px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ccc;padding:2px 3px;text-align:left}.expired td{color:#b00020;font-weight:bold}.warning td{color:#a15c00}h1{font-size:12px}</style></head><body>'
            .'<h1>'.e($this->label('title', $locale)).'</h1><p>'.e($this->company($tenantId)).' · '.e($this->label('legal_basis', $locale)).'</p>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    /**
     * @return list<string>
     */
    private function headers(string $locale): array
    {
        return array_map(fn (string $column): string => $this->label('col_'.$column, $locale), self::Columns);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|float|null>
     */
    private function cells(array $row, string $locale, bool $neutralize): array
    {
        $cells = [];

        foreach (self::Columns as $column) {
            $value = $row[$column] ?? null;

            if (in_array($column, ['direction', 'movement_type', 'deadline_status'], true) && $value !== null) {
                $value = __('subcontracting.export.'.$column.'_'.$value, [], $locale);
            }

            $cells[] = $neutralize && is_string($value) ? CsvFormulaNeutralizer::neutralize($value) : $value;
        }

        return $cells;
    }

    private function label(string $key, string $locale): string
    {
        return __('subcontracting.export.'.$key, [], $locale);
    }

    private function company(?string $tenantId): string
    {
        return $tenantId === null ? '' : (string) (Tenant::query()->whereKey($tenantId)->value('legal_name') ?: Tenant::query()->whereKey($tenantId)->value('name'));
    }
}
