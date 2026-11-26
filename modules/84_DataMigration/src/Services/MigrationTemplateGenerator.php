<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use App\Models\Translation;
use Modules\DataExport\Services\ExcelCsvWriter;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Generates downloadable .xlsx and .csv migration templates with localized column headers
 * (it-CH, de-CH, fr-CH, en, it-IT) and realistic sample rows.
 */
final class MigrationTemplateGenerator
{
    public function __construct(private readonly ExcelCsvWriter $csvWriter) {}

    /**
     * Generates a temporary file path and returns [path, filename].
     *
     * @return array{path: string, filename: string}
     */
    public function generate(MigrationTargetEntity $entity, string $format = 'xlsx', string $locale = 'en'): array
    {
        $ext = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $normalizedLocale = Translation::normalizeLocale($locale);
        $fields = $entity->schemaFields();

        $headers = [];
        $sampleRow = [];

        foreach ($fields as $fieldDef) {
            $headers[] = $fieldDef['field'];
            $sampleRow[] = $fieldDef['sample'];
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'ff_tpl_');
        $path = $tmpBase.'.'.$ext;
        if ($tmpBase !== false) {
            @unlink($tmpBase);
        }

        if ($ext === 'csv') {
            $csv = $this->csvWriter->write($headers, [$sampleRow]);
            file_put_contents($path, $csv);
        } else {
            $writer = new XlsxWriter;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues($headers));
            $writer->addRow(Row::fromValues($sampleRow));
            $writer->close();
        }

        $filename = sprintf('fatturafacile-migration-%s-%s.%s', strtolower($entity->value), $normalizedLocale, $ext);

        return ['path' => $path, 'filename' => $filename];
    }
}
