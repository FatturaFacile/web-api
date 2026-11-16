<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

use App\Models\Contact;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Generic CSV or XLSX import with a column mapper. The first row holds the headers; the caller maps each target field of
 * a dataset to one header (the preview suggests the mapping from the header names). Rows are validated one by one with
 * the rules of the dataset, the valid ones are created and the others reported with their line number, so a bad row never
 * blocks the good ones. A dry run validates without writing anything.
 *
 * Datasets: `contacts` (created as customers unless the type column says otherwise; a row whose VAT number or e-mail
 * already exists in the company is skipped as a duplicate).
 */
final class SpreadsheetImportMapper
{
    public const int MaxRows = 5000;

    /**
     * @var array<string, array<string, list<string>|string>>
     */
    private const array Datasets = [
        'contacts' => [
            'rules' => [
                'type' => ['required', 'string', 'in:client,customer,supplier,both,lead'],
                'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
                'legal_type' => ['required', 'string', 'in:company,person'],
                'company_name' => ['required_if:legal_type,company', 'nullable', 'string', 'max:255'],
                'first_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required_if:legal_type,person', 'nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email'],
                'phone' => ['nullable', 'string', 'max:50'],
                'vat_number' => ['nullable', 'string', 'max:30'],
                'fiscal_code' => ['nullable', 'string', 'max:16'],
                'pec' => ['nullable', 'email'],
                'sdi_code' => ['nullable', 'string', 'size:7'],
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function datasets(): array
    {
        return array_keys(self::Datasets);
    }

    /**
     * @return list<string> the target fields of a dataset
     */
    public function fields(string $dataset): array
    {
        $fields = array_keys(self::Datasets[$dataset]['rules']);

        return array_values(array_filter($fields, static fn (string $field): bool => $field !== 'legal_type'));
    }

    /**
     * Headers, the first rows and the mapping suggested from the header names.
     *
     * @return array{headers: list<string>, sample: list<array<string, string>>, row_count: int, suggested_mapping: array<string, string>, fields: list<string>}
     */
    public function preview(UploadedFile $file, string $dataset, int $sampleSize = 5): array
    {
        [$headers, $rows] = $this->read($file);
        $sample = array_map(fn (array $row): array => $this->assoc($headers, $row), array_slice($rows, 0, $sampleSize));

        return [
            'headers' => $headers,
            'sample' => $sample,
            'row_count' => count($rows),
            'suggested_mapping' => $this->suggest($headers, $this->fields($dataset)),
            'fields' => $this->fields($dataset),
        ];
    }

    /**
     * @param  array<string, string>  $mapping  target field => file header
     * @return array{dry_run: bool, total: int, created: int, skipped: int, failed: int, errors: list<array{line: int, messages: list<string>}>, duplicates: list<int>}
     */
    public function import(UploadedFile $file, string $dataset, array $mapping, bool $dryRun): array
    {
        [$headers, $rows] = $this->read($file);
        $mapping = array_filter($mapping, static fn (string $header, string $field): bool => in_array($header, $headers, true), ARRAY_FILTER_USE_BOTH);
        $result = ['dry_run' => $dryRun, 'total' => count($rows), 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'duplicates' => []];
        $seen = [];

        DB::transaction(function () use ($rows, $headers, $mapping, $dryRun, &$result, &$seen): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $values = $this->contactValues($mapping, $this->assoc($headers, $row));

                try {
                    $valid = Validator::make($values, self::Datasets['contacts']['rules'])->validate();
                } catch (ValidationException $exception) {
                    $result['failed']++;
                    $result['errors'][] = ['line' => $line, 'messages' => array_values(array_unique(array_merge(...array_values($exception->errors()))))];

                    continue;
                }

                $keys = array_filter([$valid['vat_number'] ?? null ? 'vat:'.mb_strtolower((string) $valid['vat_number']) : null, $valid['email'] ?? null ? 'mail:'.mb_strtolower((string) $valid['email']) : null]);

                if ($this->isDuplicate($valid, $keys, $seen)) {
                    $result['skipped']++;
                    $result['duplicates'][] = $line;

                    continue;
                }

                foreach ($keys as $key) {
                    $seen[$key] = true;
                }

                if (! $dryRun) {
                    Contact::create($valid);
                }

                $result['created']++;
            }
        });

        return $result;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function read(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open($file->getRealPath());
        $headers = [];
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn (mixed $cell): string => $this->cell($cell), $row->toArray());

                    if ($headers === []) {
                        $headers = array_map('trim', $cells);

                        continue;
                    }

                    if (count(array_filter($cells, static fn (string $cell): bool => $cell !== '')) === 0) {
                        continue;
                    }

                    if (count($rows) >= self::MaxRows) {
                        throw ValidationException::withMessages(['file' => ['The file has more than '.self::MaxRows.' rows: split it.']]);
                    }

                    $rows[] = $cells;
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw ValidationException::withMessages(['file' => ['The file is empty.']]);
        }

        return [$headers, $rows];
    }

    private function readerFor(UploadedFile $file): ReaderInterface
    {
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            return new XlsxReader;
        }

        $first = (string) strtok((string) file_get_contents($file->getRealPath(), false, null, 0, 4096), "\n");

        return new CsvReader(new CsvOptions(FIELD_DELIMITER: substr_count($first, ';') >= substr_count($first, ',') ? ';' : ','));
    }

    private function cell(mixed $cell): string
    {
        return match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
            is_bool($cell) => $cell ? '1' : '0',
            default => trim((string) $cell),
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function assoc(array $headers, array $row): array
    {
        $values = [];

        foreach ($headers as $position => $header) {
            $values[$header] = $row[$position] ?? '';
        }

        return $values;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function suggest(array $headers, array $fields): array
    {
        $normalized = [];

        foreach ($headers as $header) {
            $normalized[preg_replace('/[^a-z0-9]/', '', mb_strtolower($header))] = $header;
        }

        $suggested = [];

        foreach ($fields as $field) {
            $key = preg_replace('/[^a-z0-9]/', '', $field);

            if (isset($normalized[$key])) {
                $suggested[$field] = $normalized[$key];
            }
        }

        return $suggested;
    }

    /**
     * @param  array<string, string>  $mapping
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function contactValues(array $mapping, array $row): array
    {
        $values = [];

        foreach ($mapping as $field => $header) {
            $value = trim($row[$header] ?? '');
            $values[$field] = $value === '' ? null : $value;
        }

        $values['type'] = isset($values['type']) ? mb_strtolower((string) $values['type']) : 'customer';
        $values['country'] = isset($values['country']) ? strtoupper((string) $values['country']) : 'IT';
        $values['legal_type'] = ($values['company_name'] ?? null) !== null ? 'company' : 'person';

        return $values;
    }

    /**
     * @param  array<string, mixed>  $valid
     * @param  list<string>  $keys
     * @param  array<string, bool>  $seen
     */
    private function isDuplicate(array $valid, array $keys, array $seen): bool
    {
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                return true;
            }
        }

        $query = Contact::query();

        return ($valid['vat_number'] ?? null) !== null && (clone $query)->where('vat_number', $valid['vat_number'])->exists()
            || ($valid['email'] ?? null) !== null && (clone $query)->whereRaw('lower(email) = ?', [mb_strtolower((string) $valid['email'])])->exists();
    }
}
