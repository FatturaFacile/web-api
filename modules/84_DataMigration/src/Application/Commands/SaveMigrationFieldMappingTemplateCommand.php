<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

final readonly class SaveMigrationFieldMappingTemplateCommand
{
    /**
     * @param  array<string, mixed>  $columnMappings
     * @param  array<string, mixed>|null  $valueTransformations
     */
    public function __construct(
        public string $tenantId,
        public string $name,
        public MigrationSourceSystem $sourceSystem,
        public MigrationTargetEntity $entityType,
        public array $columnMappings,
        public ?array $valueTransformations = null,
    ) {}
}
