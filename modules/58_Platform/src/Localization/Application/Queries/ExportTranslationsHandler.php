<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Infrastructure\Persistence\TranslationReadModel;
use Modules\Platform\Localization\Infrastructure\Spreadsheet\TranslationsSpreadsheetExporter;

final class ExportTranslationsHandler
{
    public function __construct(private readonly TranslationReadModel $readModel, private readonly TranslationsSpreadsheetExporter $exporter) {}

    /**
     * @return string path of the generated file (key, then one column per requested locale)
     */
    public function __invoke(ExportTranslationsQuery $query): string
    {
        return $this->exporter->export($query->locales, $this->readModel->matrix($query->tenantId, $query->locales), $query->format);
    }
}
