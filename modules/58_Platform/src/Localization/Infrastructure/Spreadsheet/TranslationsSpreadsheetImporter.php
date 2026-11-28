<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Spreadsheet;

use InvalidArgumentException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a translation sheet: the first row is the header (key, then locale codes such as it, de-CH, en), every following
 * row is a key with its texts. Parsing only: validation against the dictionary and the diff are done by the import handler.
 */
final class TranslationsSpreadsheetImporter
{
    /**
     * @return array{locales: list<Locale>, header_errors: list<string>, cells: list<array{row: int, key: string, locale: Locale, value: string}>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = $this->reader($path, $extension);
        $reader->open($path);

        $locales = [];
        $headerErrors = [];
        $cells = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $values = array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $row->toArray());

                    if ($number === 1) {
                        [$locales, $headerErrors] = $this->header($values);

                        if ($headerErrors !== []) {
                            break;
                        }

                        continue;
                    }

                    $key = $values[0] ?? '';

                    if ($key === '' && count(array_filter($values, static fn (string $value): bool => $value !== '')) === 0) {
                        continue;
                    }

                    foreach ($locales as $index => $locale) {
                        $value = $values[$index] ?? '';

                        if ($value !== '') {
                            $cells[] = ['row' => $number, 'key' => $key, 'locale' => $locale, 'value' => $value];
                        }
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return ['locales' => array_values($locales), 'header_errors' => $headerErrors, 'cells' => $cells];
    }

    /**
     * @param  list<string>  $header
     * @return array{0: array<int, Locale>, 1: list<string>}
     */
    private function header(array $header): array
    {
        $errors = [];
        $locales = [];

        if (strtolower($header[0] ?? '') !== 'key') {
            $errors[] = 'The first column of the header must be "key".';
        }

        foreach (array_slice($header, 1, null, true) as $index => $name) {
            if ($name === '') {
                continue;
            }

            $locale = Locale::tryFrom($name);

            if ($locale === null) {
                $errors[] = sprintf('Unknown locale column "%s".', $name);

                continue;
            }

            $locales[$index] = $locale;
        }

        if ($locales === [] && $errors === []) {
            $errors[] = 'The header must name at least one locale column.';
        }

        return [$locales, $errors];
    }

    private function reader(string $path, string $extension): ReaderInterface
    {
        return match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv' => new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->delimiter($path))),
            default => throw new InvalidArgumentException("Unsupported import format [{$extension}]."),
        };
    }

    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }
}
