<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use DateTimeInterface;
use Modules\ProductCatalog\Exceptions\CatalogException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Reads and writes the catalog exchange file (.csv or .xlsx) with the OpenSpout streaming reader and writer. The first row holds
 * the column names; the schema (the allowed columns) is fixed so that an export can always be imported back.
 */
final class CatalogSpreadsheet
{
    /**
     * The columns of the exchange file, in the order of the export. The packagings column holds
     * `CODE|UOM|UNITS_PER_PARENT|PARENT_CODE|ROLE|GTIN` entries separated by `;`, the kit components column `SKU:QUANTITY[:optional]`
     * entries separated by `;`, the attributes column `Name=Value` entries separated by `|`.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'sku', 'name', 'description', 'product_type', 'kit_type', 'parent_sku', 'stock_uom', 'packagings', 'kit_components', 'attributes',
        'barcode', 'cost_price', 'sale_price', 'price_delta', 'price_delta_type', 'cost_delta', 'cost_delta_type', 'tax_rate',
        'tares_code', 'taric_code', 'country_of_origin', 'vat_code_it', 'vat_code_ch', 'net_weight_kg', 'gross_weight_kg',
        'track_inventory', 'is_active',
    ];

    /**
     * @var list<string>
     */
    public const array REQUIRED = ['sku', 'name'];

    public const int MAX_ROWS = 5000;

    /**
     * The rows of an uploaded file as lists keyed by column name, with the line number of the file (the header is line 1).
     *
     * @return list<array{line: int, cells: array<string, string|null>}>
     *
     * @throws CatalogException
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new CatalogException('import_unsupported_format');
        }

        try {
            $reader = $extension === 'csv' ? $this->csvReader($path) : new XlsxReader;
            $reader->open($path);
            $header = null;
            $rows = [];
            $line = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $values = array_map($this->text(...), $row->toArray());

                    if ($header === null) {
                        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name)), $values);
                        $this->assertHeader($header);

                        continue;
                    }

                    if (array_filter($values, static fn (?string $value): bool => $value !== null) === []) {
                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw new CatalogException('import_too_many_rows', 422, ['max' => self::MAX_ROWS]);
                    }

                    $cells = [];

                    foreach ($header as $position => $name) {
                        $cells[$name] = $values[$position] ?? null;
                    }

                    $rows[] = ['line' => $line, 'cells' => $cells];
                }

                break; // only the first sheet carries the data
            }

            $reader->close();
        } catch (CatalogException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CatalogException('import_unreadable_file');
        }

        if ($header === null) {
            throw new CatalogException('import_empty_file');
        }

        return $rows;
    }

    /**
     * Writes the rows (lists in the order of COLUMNS) to a file and returns its path. Text cells are neutralised against formula
     * injection.
     *
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public function write(iterable $rows, string $extension, ?string $path = null): string
    {
        $path ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog_'.bin2hex(random_bytes(8)).'.'.$extension;
        $writer = $extension === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::COLUMNS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(static fn (string|int|float|null $cell): string => CsvFormulaNeutralizer::neutralize($cell ?? ''), $row)));
        }

        $writer->close();

        return $path;
    }

    private function csvReader(string $path): CsvReader
    {
        $first = (string) fgets(fopen($path, 'rb') ?: throw new CatalogException('import_unreadable_file'));

        return new CsvReader(new CsvReaderOptions(FIELD_DELIMITER: substr_count($first, ';') > substr_count($first, ',') ? ';' : ','));
    }

    private function text(mixed $cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d');
        }

        if (is_bool($cell)) {
            return $cell ? '1' : '0';
        }

        if (is_float($cell)) {
            $text = rtrim(rtrim(sprintf('%.8F', $cell), '0'), '.');
        } else {
            $text = trim((string) $cell);
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $header
     *
     * @throws CatalogException
     */
    private function assertHeader(array $header): void
    {
        $missing = array_values(array_diff(self::REQUIRED, $header));

        if ($missing !== []) {
            throw new CatalogException('import_missing_columns', 422, ['columns' => implode(', ', $missing)]);
        }

        $unknown = array_values(array_diff(array_filter($header, static fn (string $name): bool => $name !== ''), self::COLUMNS));

        if ($unknown !== []) {
            throw new CatalogException('import_unknown_columns', 422, ['columns' => implode(', ', $unknown)]);
        }

        if (count(array_unique(array_filter($header))) !== count(array_filter($header))) {
            throw new CatalogException('import_duplicate_columns');
        }
    }
}
