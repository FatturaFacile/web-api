<?php

declare(strict_types=1);

namespace Modules\DataMigration\Models;

use Database\Factories\MigrationFieldMappingTemplateFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property MigrationSourceSystem $source_system
 * @property MigrationTargetEntity $entity_type
 * @property array<string, mixed> $column_mappings_json
 * @property array<string, mixed>|null $value_transformations_json
 * @property bool $is_system_preset
 */
final class MigrationFieldMappingTemplate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    /** @use HasFactory<MigrationFieldMappingTemplateFactory> */
    use HasFactory;

    protected $table = 'migration_field_mapping_templates';

    protected static string $factory = MigrationFieldMappingTemplateFactory::class;

    protected function casts(): array
    {
        return [
            'source_system' => MigrationSourceSystem::class,
            'entity_type' => MigrationTargetEntity::class,
            'column_mappings_json' => 'array',
            'value_transformations_json' => 'array',
            'is_system_preset' => 'boolean',
        ];
    }
}
