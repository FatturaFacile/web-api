<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Spreadsheet;

use App\Services\Security\CsvFormulaNeutralizer;
use InvalidArgumentException;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes the translation matrix (key, then one column per locale) as .xlsx or .csv with the OpenSpout streaming writers.
 * CSV text is neutralised against spreadsheet formula injection; xlsx text is stored as strings, never as formulas.
 */
final class TranslationsSpreadsheetExporter
{
    /**
     * @param  list<Locale>  $locales
     * @param  list<array<string, string>>  $matrix
     * @return string path of the written file (the caller deletes it)
     */
    public function export(array $locales, array $matrix, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'i18n-export-');

        if ($path === false) {
            throw new InvalidArgumentException('Cannot create a temporary export file.');
        }

        $writer = match ($format) {
            'xlsx' => new XlsxWriter,
            'csv' => new CsvWriter,
            default => throw new InvalidArgumentException("Unsupported export format [{$format}]."),
        };

        $writer->openToFile($path);

        $header = ['key', ...array_map(static fn (Locale $locale): string => $locale->value(), $locales)];
        $writer->addRow(Row::fromValues($header));

        foreach ($matrix as $row) {
            $values = [$row['key'], ...array_map(static fn (Locale $locale): string => $row[$locale->value()] ?? '', $locales)];
            $writer->addRow(Row::fromValues($format === 'csv' ? CsvFormulaNeutralizer::row($values) : $values));
        }

        $writer->close();

        return $path;
    }
}
