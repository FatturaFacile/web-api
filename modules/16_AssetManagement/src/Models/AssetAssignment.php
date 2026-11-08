<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Database\Factories\AssetAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eloquent model of the asset assignment records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $asset_id
 * @property string|null $assignment_number
 * @property string|null $assignee_type
 * @property string|null $assignee_id
 * @property string|null $assignee_name
 * @property string|null $department_id
 * @property string|null $project_id
 * @property string|null $location_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $expected_return_at
 * @property Carbon|null $returned_at
 * @property string|null $condition_on_assignment
 * @property string|null $condition_on_return
 * @property string|null $status
 * @property string|null $assigned_by
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class AssetAssignment extends Model
{
    /** @use HasFactory<AssetAssignmentFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'eam_asset_assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    public function __construct(array $attributes = [])
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Str::uuid7();
        }

        parent::__construct($attributes);

        if (! isset($this->attributes['id'])) {
            $this->attributes['id'] = $attributes['id'];
        }
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'date',
            'expected_return_at' => 'date',
            'returned_at' => 'date',
            'metadata' => 'array',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): AssetAssignmentFactory
    {
        return AssetAssignmentFactory::new();
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<AssetLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }
}
