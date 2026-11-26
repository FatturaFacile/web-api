<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;

/**
 * @mixin MigrationFieldMappingTemplate
 */
final class MigrationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'source_system' => $this->source_system->value,
            'entity_type' => $this->entity_type->value,
            'column_mappings_json' => $this->column_mappings_json,
            'value_transformations_json' => $this->value_transformations_json,
            'is_system_preset' => $this->is_system_preset,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
