<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataMigration\Models\MigrationValidationError;

/**
 * @mixin MigrationValidationError
 */
final class MigrationValidationErrorResource extends JsonResource
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
            'source_record_identifier' => $this->source_record_identifier,
            'entity_type' => $this->entity_type,
            'field_name' => $this->field_name,
            'raw_value' => $this->raw_value,
            'error_code' => $this->error_code,
            'message_key' => $this->message_key,
            'suggested_fix' => $this->suggested_fix,
            'is_resolved' => $this->is_resolved,
        ];
    }
}
