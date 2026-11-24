<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use Modules\DataExport\Services\ExcelCsvWriter;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Generates an auditor- and commercialista-ready reconciliation worksheet for Mod 022 Ratei & Risconti
 * (Transitorische Aktiven und Passiven) as CSV (repository ExcelCsvWriter conventions: UTF-8 BOM, semicolon delimiter,
 * CRLF, formula-injection neutralization) or as a native .xlsx workbook (OpenSpout: bold frozen header, typed numeric
 * cells for days and amounts, text neutralised against formula injection and never written as a formula).
 */
final readonly class AccrualReconciliationExporter
{
    /** @var list<string> */
    private const array Columns = [
        'fiscal_year',
        'cutoff_date',
        'reversal_date',
        'accrual_type',
        'source_type',
        'document_number',
        'document_date',
        'description',
        'income_account_code',
        'income_account_name',
        'balance_sheet_account_code',
        'balance_sheet_account_name',
        'competence_start_date',
        'competence_end_date',
        'total_days',
        'period_days',
        'transitory_days',
        'currency',
        'total_amount',
        'recognized_amount',
        'transitory_amount',
        'status',
        'closing_journal_entry_id',
        'closing_journal_entry_number',
        'closing_journal_posting_date',
        'reversal_journal_entry_id',
        'reversal_journal_entry_number',
        'reversal_journal_posting_date',
    ];

    public function __construct(
        private ExcelCsvWriter $writer,
        private AccrualScheduleService $schedule,
    ) {}

    public const array Formats = ['csv', 'xlsx'];

    /** @var list<string> */
    private const array NumericColumns = ['total_days', 'period_days', 'transitory_days', 'total_amount', 'recognized_amount', 'transitory_amount'];

    public function fileName(string $yearLabel, string $format = 'csv'): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $yearLabel) ?: 'schedule';

        return "ratei-risconti-reconciliation-{$safe}.".($format === 'xlsx' ? 'xlsx' : 'csv');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filename: string, content: string, content_type: string}
     */
    public function export(string $tenantId, array $filters = [], string $format = 'csv'): array
    {
        $summary = $this->schedule->summary($tenantId, $filters);
        $rows = $this->rows($summary, $format === 'xlsx');

        if ($format === 'xlsx') {
            return [
                'filename' => $this->fileName($summary['year_label'], 'xlsx'),
                'content' => $this->xlsx($rows),
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        return [
            'filename' => $this->fileName($summary['year_label']),
            'content' => $this->writer->write(self::Columns, $rows),
            'content_type' => 'text/csv; charset=UTF-8',
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<list<mixed>>
     */
    private function rows(array $summary, bool $raw): array
    {
        $amount = static fn (mixed $value): mixed => $raw ? round((float) $value, 2) : self::decimalComma((string) $value);

        return array_map(static fn (array $item): array => [
            $summary['year_label'],
            $item['cutoff_date'],
            $item['reversal_date'],
            $item['accrual_type'],
            $item['source_type'],
            $item['source_document_number'] ?? '',
            $item['document_date'] ?? '',
            $item['description'],
            $item['income_statement_account_code'] ?? '',
            $item['income_statement_account_name'] ?? '',
            $item['balance_sheet_account_code'] ?? '',
            $item['balance_sheet_account_name'] ?? '',
            $item['competence_start_date'],
            $item['competence_end_date'],
            $item['total_days'],
            $item['period_days'],
            $item['transitory_days'],
            $item['currency'],
            $amount($item['total_amount']),
            $amount($item['recognized_amount']),
            $amount($item['transitory_amount']),
            $item['status'],
            $item['closing_journal_entry_id'] ?? '',
            $item['closing_journal_entry_number'] ?? '',
            $item['closing_journal_posting_date'] ?? '',
            $item['reversal_journal_entry_id'] ?? '',
            $item['reversal_journal_entry_number'] ?? '',
            $item['reversal_journal_posting_date'] ?? '',
        ], $summary['items']);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'accr');

        if ($path === false) {
            throw new RuntimeException('Cannot create the temporary workbook file.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Reconciliation');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

            $header = (new Style)->withFontBold(true)->withBackgroundColor('E7E6E6');
            $amountStyle = (new Style)->withFormat('#,##0.00');
            $writer->addRow(Row::fromValuesWithStyle(self::Columns, $header));

            foreach ($rows as $row) {
                $cells = [];

                foreach (self::Columns as $index => $column) {
                    $value = $row[$index] ?? '';
                    $cells[] = in_array($column, self::NumericColumns, true) && is_numeric($value)
                        ? new NumericCell($value + 0, str_ends_with($column, '_amount') ? $amountStyle : null)
                        : new StringCell(CsvFormulaNeutralizer::neutralize($value), null);
                }

                $writer->addRow(new Row($cells));
            }

            $writer->close();
            $content = file_get_contents($path);

            if ($content === false) {
                throw new RuntimeException('Cannot read the generated workbook.');
            }

            return $content;
        } finally {
            @unlink($path);
        }
    }

    private static function decimalComma(string $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }
}
