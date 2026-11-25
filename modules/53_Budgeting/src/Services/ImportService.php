<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetImport;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports the budget of a draft version from an Excel (.xlsx) or CSV file in long format: one row per account, cost
 * centre, project and period (month 1-12, or quarter 1-4 for a quarterly version) with the amount and optionally the
 * quantity and unit price. The whole file is validated first and every problem is reported with its row; the import
 * is all or nothing, so a file with errors changes nothing. Mode "replace" empties the version first, "merge"
 * overwrites only the cells in the file.
 */
final readonly class ImportService
{
    public const int MaxRows = 5000;

    /** @var list<string> */
    public const array Columns = ['account_code', 'cost_center_code', 'project_number', 'period', 'amount', 'quantity', 'unit_price', 'notes'];

    private const array Required = ['account_code', 'period', 'amount'];

    public function __construct(private LedgerLookup $ledger, private VersionService $versions, private GridService $grid, private WorkbookWriter $writer, private AuditTrail $audit) {}

    /**
     * The template workbook of the import for a version (header and one example row); returns the temporary file path.
     */
    public function template(BudgetVersion $version): string
    {
        return $this->writer->write([[
            'name' => 'Budget',
            'headers' => self::Columns,
            'rows' => [['6000', 'CC-100', '', 1, 1000.0, 10.0, 100.0, '']],
        ]]);
    }

    /**
     * @return array{import: BudgetImport, rows: int, errors: list<array{row: int, field: string, message: string}>}
     */
    public function import(BudgetVersion $version, UploadedFile $file, string $mode, bool $validateOnly, ?string $userId): array
    {
        $this->versions->assertDraft($version);
        [$headers, $rows] = $this->read($file);
        $errors = [];
        $cells = $this->parse($version, $headers, $rows, $errors);
        $status = $errors !== [] ? BudgetImport::Failed : ($validateOnly ? BudgetImport::Validated : BudgetImport::Imported);

        if ($status === BudgetImport::Imported) {
            DB::transaction(function () use ($version, $mode, $cells, $userId): void {
                if ($mode === BudgetImport::ModeReplace) {
                    BudgetLine::query()->where('version_id', $version->id)->get()->each->delete();
                }

                $this->grid->save($version, $cells, $userId);
            });
        }

        $import = BudgetImport::create([
            'tenant_id' => $version->tenant_id,
            'version_id' => $version->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'format' => strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv',
            'mode' => $mode,
            'status' => $status,
            'total_rows' => count($rows),
            'imported_rows' => $status === BudgetImport::Imported ? count($cells) : 0,
            'error_rows' => count(array_unique(array_column($errors, 'row'))),
            'errors' => array_slice($errors, 0, 500),
            'user_id' => $userId,
        ]);

        $this->audit->record($version->tenant_id, BudgetAuditEvent::SubjectVersion, $version->id, 'import', $userId, null, null, null, ['import_id' => $import->id, 'status' => $status, 'rows' => count($rows), 'mode' => $mode]);

        return ['import' => $import, 'rows' => count($rows), 'errors' => $errors];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array{0: int, 1: list<string>}>  $rows
     * @param  list<array{row: int, field: string, message: string}>  $errors
     * @return list<array<string, mixed>>
     */
    private function parse(BudgetVersion $version, array $headers, array $rows, array &$errors): array
    {
        $index = array_flip(array_map(static fn (string $header): string => strtolower(trim($header)), $headers));

        foreach (self::Required as $column) {
            if (! isset($index[$column])) {
                $errors[] = ['row' => 1, 'field' => $column, 'message' => self::text('budgeting_errors.import.missing_column', ['column' => $column])];
            }
        }

        if ($errors !== []) {
            return [];
        }

        $get = static fn (array $row, string $column): string => isset($index[$column]) ? trim($row[$index[$column]] ?? '') : '';
        $accountCodes = array_values(array_unique(array_filter(array_map(fn (array $entry): string => $get($entry[1], 'account_code'), $rows))));
        $costCenterCodes = array_values(array_unique(array_filter(array_map(fn (array $entry): string => $get($entry[1], 'cost_center_code'), $rows))));
        $projectCodes = array_values(array_unique(array_filter(array_map(fn (array $entry): string => $get($entry[1], 'project_number'), $rows))));
        $accounts = $this->ledger->idsByCode($version->tenant_id, 'account', $accountCodes);
        $costCenters = $this->ledger->idsByCode($version->tenant_id, 'cost_center', $costCenterCodes);
        $projects = $this->ledger->idsByCode($version->tenant_id, 'project', $projectCodes);
        $slots = $this->grid->slotCount($version);
        $cells = [];
        $seen = [];

        foreach ($rows as [$number, $row]) {
            $before = count($errors);
            $code = $get($row, 'account_code');
            $costCenterCode = $get($row, 'cost_center_code');
            $projectCode = $get($row, 'project_number');
            $period = $get($row, 'period');
            $amount = $this->number($get($row, 'amount'));
            $quantity = $this->number($get($row, 'quantity'));
            $unitPrice = $this->number($get($row, 'unit_price'));

            if ($code === '' || ! isset($accounts[$code])) {
                $errors[] = ['row' => $number, 'field' => 'account_code', 'message' => self::text('budgeting_errors.import.account_unknown', ['code' => $code])];
            }

            if ($costCenterCode !== '' && ! isset($costCenters[$costCenterCode])) {
                $errors[] = ['row' => $number, 'field' => 'cost_center_code', 'message' => self::text('budgeting_errors.import.cost_center_unknown', ['code' => $costCenterCode])];
            }

            if ($projectCode !== '' && ! isset($projects[$projectCode])) {
                $errors[] = ['row' => $number, 'field' => 'project_number', 'message' => self::text('budgeting_errors.import.project_unknown', ['code' => $projectCode])];
            }

            if (preg_match('/^\d+$/', $period) !== 1 || (int) $period < 1 || (int) $period > $slots) {
                $errors[] = ['row' => $number, 'field' => 'period', 'message' => self::text('budgeting_errors.import.period_invalid', ['max' => $slots])];
            }

            if ($amount === null || bccomp($amount, '0', 4) < 0) {
                $errors[] = ['row' => $number, 'field' => 'amount', 'message' => self::text('budgeting_errors.import.amount_invalid')];
            }

            foreach (['quantity' => $quantity, 'unit_price' => $unitPrice] as $field => $value) {
                if ($get($row, $field) !== '' && ($value === null || bccomp($value, '0', 4) < 0)) {
                    $errors[] = ['row' => $number, 'field' => $field, 'message' => self::text('budgeting_errors.import.number_invalid')];
                }
            }

            if (count($errors) > $before) {
                continue;
            }

            $key = $code."\0".$costCenterCode."\0".$projectCode."\0".$period;

            if (isset($seen[$key])) {
                $errors[] = ['row' => $number, 'field' => 'period', 'message' => self::text('budgeting_errors.import.duplicate', ['row' => $seen[$key]])];

                continue;
            }

            $seen[$key] = $number;
            $cells[] = [
                'account_id' => $accounts[$code],
                'cost_center_id' => $costCenterCode === '' ? null : $costCenters[$costCenterCode],
                'project_id' => $projectCode === '' ? null : $projects[$projectCode],
                'slot' => (int) $period,
                'amount' => bcadd((string) $amount, '0', 2),
                'quantity' => $get($row, 'quantity') === '' ? null : (string) $quantity,
                'unit_price' => $get($row, 'unit_price') === '' ? null : (string) $unitPrice,
                'notes' => $get($row, 'notes') === '' ? null : mb_substr($get($row, 'notes'), 0, 500),
            ];
        }

        return $errors === [] ? $cells : [];
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }

    private function number(string $value): ?string
    {
        $value = str_replace(' ', '', $value);

        if (preg_match('/^-?\d+,\d+$/', $value) === 1) {
            $value = str_replace(',', '.', $value);
        }

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{0: list<string>, 1: list<array{0: int, 1: list<string>}>}
     */
    private function read(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open((string) $file->getRealPath());
        $headers = [];
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $position => $row) {
                    $cells = array_map(static fn (mixed $cell): string => match (true) {
                        $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
                        is_bool($cell) => $cell ? '1' : '0',
                        default => trim((string) $cell),
                    }, $row->toArray());

                    if ($headers === []) {
                        $headers = $cells;

                        continue;
                    }

                    if (count(array_filter($cells, static fn (string $cell): bool => $cell !== '')) === 0) {
                        continue;
                    }

                    if (count($rows) >= self::MaxRows) {
                        throw ValidationException::withMessages(['file' => [__('budgeting_errors.import.too_many_rows', ['max' => self::MaxRows])]]);
                    }

                    $rows[] = [(int) $position, $cells];
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw ValidationException::withMessages(['file' => [__('budgeting_errors.import.empty')]]);
        }

        return [$headers, $rows];
    }

    private function readerFor(UploadedFile $file): ReaderInterface
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages(['file' => [__('budgeting_errors.import.xls_unsupported')]]);
        }

        if ($extension === 'xlsx') {
            return new XlsxReader;
        }

        $first = (string) strtok((string) file_get_contents((string) $file->getRealPath(), false, null, 0, 4096), "\n");

        return new CsvReader(new CsvOptions(FIELD_DELIMITER: substr_count($first, ';') >= substr_count($first, ',') ? ';' : ','));
    }
}
