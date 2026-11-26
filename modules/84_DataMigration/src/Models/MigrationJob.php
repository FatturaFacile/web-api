<?php

declare(strict_types=1);

namespace Modules\DataMigration\Models;

use App\Models\User;
use Database\Factories\MigrationJobFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $legal_entity_id
 * @property string|null $created_by_user_id
 * @property MigrationSourceSystem $source_system
 * @property list<string> $target_entities
 * @property MigrationJobStatus $status
 * @property bool $dry_run_passed
 * @property int $total_rows
 * @property int $imported_rows
 * @property int $error_rows
 * @property string|null $source_file_path
 * @property array<string, mixed>|null $source_metadata_json
 * @property array<string, mixed>|null $dry_run_summary_json
 * @property array<string, mixed>|null $rollback_manifest_json
 * @property Carbon|null $dry_run_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $rolled_back_at
 */
final class MigrationJob extends BaseEnterpriseModel
{
    /** @use HasFactory<MigrationJobFactory> */
    use HasFactory;

    protected $table = 'migration_jobs';

    protected static string $factory = MigrationJobFactory::class;

    protected function casts(): array
    {
        return [
            'source_system' => MigrationSourceSystem::class,
            'target_entities' => 'array',
            'status' => MigrationJobStatus::class,
            'dry_run_passed' => 'boolean',
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'error_rows' => 'integer',
            'source_metadata_json' => 'array',
            'dry_run_summary_json' => 'array',
            'rollback_manifest_json' => 'array',
            'dry_run_at' => 'datetime',
            'completed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<MigrationStagingRow, $this>
     */
    public function stagingRows(): HasMany
    {
        return $this->hasMany(MigrationStagingRow::class, 'migration_job_id')->orderBy('row_index');
    }

    /**
     * @return HasMany<MigrationValidationError, $this>
     */
    public function validationErrors(): HasMany
    {
        return $this->hasMany(MigrationValidationError::class, 'migration_job_id')->orderBy('row_index');
    }
}
