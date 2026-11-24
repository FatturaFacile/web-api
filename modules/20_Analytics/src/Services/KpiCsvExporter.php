<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use Modules\DataExport\Services\ExcelCsvWriter;

/**
 * CSV of the reporting layer, written with the shared Excel-compatible writer of the export module: semicolons, UTF-8
 * byte order mark and every text cell neutralised against spreadsheet formula injection (names of customers, suppliers
 * and projects are user input). The detail export is capped at {@see KpiCatalog::MaxExportRows} rows.
 */
final readonly class KpiCsvExporter
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ExcelCsvWriter $writer) {}

    /**
     * One row per indicator: value, comparison, change.
     *
     * @param  array{period: array{from: string, to: string}, comparison_period: ?array{from: string, to: string}, kpis: list<array<string, mixed>>}  $report
     */
    public function summary(array $report): string
    {
        $rows = array_map(static fn (array $kpi): array => [
            $kpi['key'],
            $kpi['label'],
            $kpi['unit'],
            $kpi['kind'],
            $kpi['value'] ?? '',
            $kpi['comparison']['value'] ?? '',
            $kpi['change'] ?? '',
            $kpi['change_percent'] ?? '',
            $report['period']['from'],
            $report['period']['to'],
            $kpi['reason'] ?? '',
        ], $report['kpis']);

        return $this->writer->write(['kpi', 'label', 'unit', 'kind', 'value', 'comparison_value', 'change', 'change_percent', 'period_from', 'period_to', 'note'], $rows);
    }

    /**
     * The table behind one indicator.
     *
     * @param  list<array<string, scalar|null>>  $rows  at most {@see KpiCatalog::MaxExportRows}
     */
    public function detail(array $rows): string
    {
        if ($rows === []) {
            return $this->writer->write(['no_data'], []);
        }

        return $this->writer->write(array_keys($rows[0]), array_map(array_values(...), $rows));
    }

    /**
     * Whether a detail export hit the cap and may be incomplete.
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function isTruncated(array $rows): bool
    {
        return count($rows) >= KpiCatalog::MaxExportRows;
    }
}
