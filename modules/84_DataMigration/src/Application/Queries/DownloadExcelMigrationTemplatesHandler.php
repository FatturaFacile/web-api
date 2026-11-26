<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

use Modules\DataMigration\Services\MigrationTemplateGenerator;

final readonly class DownloadExcelMigrationTemplatesHandler
{
    public function __construct(private MigrationTemplateGenerator $generator) {}

    /**
     * @return array{path: string, filename: string}
     */
    public function handle(DownloadExcelMigrationTemplatesQuery $query): array
    {
        return $this->generator->generate($query->entityType, $query->format, $query->locale);
    }
}
