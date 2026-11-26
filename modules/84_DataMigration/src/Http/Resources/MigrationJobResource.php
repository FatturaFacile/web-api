<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataMigration\Models\MigrationJob;

/**
 * @mixin MigrationJob
 */
final class MigrationJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'legal_entity_id' => $this->legal_entity_id,
            'created_by_user_id' => $this->created_by_user_id,
            'source_system' => $this->source_system->value,
            'target_entities' => $this->target_entities,
            'status' => $this->status->value,
            'dry_run_passed' => $this->dry_run_passed,
            'total_rows' => $this->total_rows,
            'imported_rows' => $this->imported_rows,
            'error_rows' => $this->error_rows,
            'source_file_path' => $this->source_file_path,
            'source_metadata_json' => $this->source_metadata_json,
            'dry_run_summary_json' => $this->dry_run_summary_json,
            'rollback_manifest_json' => $this->rollback_manifest_json,
            'dry_run_at' => $this->dry_run_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'rolled_back_at' => $this->rolled_back_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
