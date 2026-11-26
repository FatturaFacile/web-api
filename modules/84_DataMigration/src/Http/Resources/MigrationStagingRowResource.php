<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataMigration\Models\MigrationStagingRow;

/**
 * @mixin MigrationStagingRow
 */
final class MigrationStagingRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'migration_job_id' => $this->migration_job_id,
            'row_index' => $this->row_index,
            'entity_type' => $this->entity_type,
            'source_record_identifier' => $this->source_record_identifier,
            'raw_payload_json' => $this->raw_payload_json,
            'mapped_payload_json' => $this->mapped_payload_json,
            'is_valid' => $this->is_valid,
            'is_imported' => $this->is_imported,
            'imported_record_id' => $this->imported_record_id,
        ];
    }
}
