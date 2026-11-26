<?php

declare(strict_types=1);

namespace Modules\DataMigration\Models;

use Database\Factories\MigrationValidationErrorFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $migration_job_id
 * @property int $row_index
 * @property string|null $source_record_identifier
 * @property string $entity_type
 * @property string $field_name
 * @property string|null $raw_value
 * @property string $error_code
 * @property string $message_key
 * @property string|null $suggested_fix
 * @property bool $is_resolved
 */
final class MigrationValidationError extends BaseEnterpriseModel
{
    /** @use HasFactory<MigrationValidationErrorFactory> */
    use HasFactory;

    protected $table = 'migration_validation_errors';

    protected static string $factory = MigrationValidationErrorFactory::class;

    protected function casts(): array
    {
        return [
            'row_index' => 'integer',
            'is_resolved' => 'boolean',
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
