<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Application\Queries;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;

/**
 * CSV and PDF exports of the registers. The column titles are the stable column keys (the client translates them); the PDF is a plain table.
 */
final class DatasetExporter
{
    public const array DATASETS = ['mrn-lots', 'cgu-ledger', 'vat-register', 'aeo-audits'];

    private const int MAX_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{columns: list<string>, rows: list<list<string>>}
     *
     * @throws BondedWarehouseException unknown_dataset
     */
    public function table(string $dataset, array $filters, RegistryQueries $queries): array
    {
        return match ($dataset) {
            'mrn-lots' => $this->build($queries->mrnLots($filters, self::MAX_ROWS)->items(), [
                'mrn_number', 'mrn_item_number', 'declaration_type', 'entry_date', 'taric_hs_code', 'country_of_origin', 'initial_quantity', 'remaining_quantity',
                'remaining_net_weight_kg', 'customs_statistical_value', 'suspended_duty_amount', 'suspended_vat_amount', 'cgu_engaged_amount', 'customs_status',
            ]),
            'cgu-ledger' => $this->build($queries->cguLedger($filters, self::MAX_ROWS)->items(), [
                'recorded_at', 'reference_type', 'movement_direction', 'duty_amount', 'vat_amount', 'total_cgu_delta', 'running_utilized_balance', 'remaining_plafond',
            ]),
            'vat-register' => $this->build($queries->vatRegister($filters, self::MAX_ROWS)->items(), [
                'register_progressive_number', 'movement_date', 'movement_type', 'lot_reference', 'quantity', 'base_goods_value', 'storage_and_processing_services_value',
                'total_taxable_extraction_base', 'vat_rate_percent', 'vat_amount', 'sdi_document_type', 'f24_payment_reference', 'f24_payment_date',
            ]),
            'aeo-audits' => $this->build($queries->aeoAudits($filters, self::MAX_ROWS)->items(), [
                'audit_date', 'audit_type', 'wms_physical_quantity', 'customs_ledger_quantity', 'discrepancy_quantity', 'discrepancy_status', 'partner_or_carrier_name',
                'partner_aeo_status', 'security_seal_verified', 'findings',
            ]),
            default => throw BondedWarehouseException::because('unknown_dataset', ['dataset' => $dataset], 404),
        };
    }

    /**
     * @param  array{columns: list<string>, rows: list<list<string>>}  $table
     */
    public function csv(array $table): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $table['columns'], ';');

        foreach ($table['rows'] as $row) {
            // A cell never starts a formula in a spreadsheet.
            fputcsv($handle, array_map(static fn (string $cell): string => $cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) && ! is_numeric($cell) ? "'".$cell : $cell, $row), ';');
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  array{columns: list<string>, rows: list<list<string>>}  $table
     */
    public function pdf(string $dataset, array $table): string
    {
        $head = '<tr>'.implode('', array_map(static fn (string $c): string => '<th>'.e($c).'</th>', $table['columns'])).'</tr>';
        $body = implode('', array_map(static fn (array $row): string => '<tr>'.implode('', array_map(static fn (string $c): string => '<td>'.e($c).'</td>', $row)).'</tr>', $table['rows']));
        $style = 'body{font-family:DejaVu Sans,sans-serif;font-size:7px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:2px;text-align:left}th{background:#eee}';

        return Pdf::loadHTML('<html><head><meta charset="utf-8"><style>'.$style.'</style></head><body><h3>'.e($dataset).'</h3><table>'.$head.$body.'</table></body></html>')
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  list<Model>  $items
     * @param  list<string>  $columns
     * @return array{columns: list<string>, rows: list<list<string>>}
     */
    private function build(array $items, array $columns): array
    {
        $rows = array_map(static fn (Model $item): array => array_map(static function (string $column) use ($item): string {
            $value = $item->getAttribute($column);

            return match (true) {
                $value instanceof \BackedEnum => (string) $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'.($column === 'recorded_at' ? ' H:i:s' : '')),
                is_bool($value) => $value ? '1' : '0',
                default => (string) ($value ?? ''),
            };
        }, $columns), $items);

        return ['columns' => $columns, 'rows' => array_values($rows)];
    }
}
