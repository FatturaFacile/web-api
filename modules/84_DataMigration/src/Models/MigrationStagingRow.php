<?php

declare(strict_types=1);

namespace Modules\DataMigration\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $migration_job_id
 * @property int $row_index
 * @property string $entity_type
 * @property string|null $source_record_identifier
 * @property array<string, mixed> $raw_payload_json
 * @property array<string, mixed>|null $mapped_payload_json
 * @property bool $is_valid
 * @property bool $is_imported
 * @property string|null $imported_record_id
 */
final class MigrationStagingRow extends BaseEnterpriseModel
{
    protected $table = 'migration_staging_rows';

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'raw_payload_json' => 'array',
            'mapped_payload_json' => 'array',
            'is_valid' => 'boolean',
            'is_imported' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MigrationJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(MigrationJob::class, 'migration_job_id');
    }
}
