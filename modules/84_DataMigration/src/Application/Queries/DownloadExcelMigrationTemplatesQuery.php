<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Queries;

use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

final readonly class DownloadExcelMigrationTemplatesQuery
{
    public function __construct(
        public MigrationTargetEntity $entityType,
        public string $format = 'xlsx',
        public string $locale = 'en',
    ) {}
}
