<?php

declare(strict_types=1);

namespace Modules\DataMigration\Application\Commands;

use Modules\DataMigration\Models\MigrationFieldMappingTemplate;

final readonly class SaveMigrationFieldMappingTemplateHandler
{
    public function handle(SaveMigrationFieldMappingTemplateCommand $command): MigrationFieldMappingTemplate
    {
        return MigrationFieldMappingTemplate::query()->create([
            'tenant_id' => $command->tenantId,
            'name' => $command->name,
            'source_system' => $command->sourceSystem,
            'entity_type' => $command->entityType,
            'column_mappings_json' => $command->columnMappings,
            'value_transformations_json' => $command->valueTransformations ?? [],
            'is_system_preset' => false,
        ]);
    }
}
