<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Database\Factories\MaintenanceActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eloquent model of the maintenance activity records of the asset management module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $work_order_id
 * @property string|null $asset_id
 * @property string|null $activity_code
 * @property string|null $activity_type
 * @property string|null $description
 * @property string|null $performed_by
 * @property string|null $duration_hours
 * @property string|null $labor_cost
 * @property string|null $spare_parts_cost
 * @property array<string, mixed>|null $spare_parts_consumed
 * @property string|null $status
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class MaintenanceActivity extends Model
{
    /** @use HasFactory<MaintenanceActivityFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'eam_maintenance_activities';

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
            'duration_hours' => 'decimal:2',
            'labor_cost' => 'decimal:4',
            'spare_parts_cost' => 'decimal:4',
            'spare_parts_consumed' => 'array',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): MaintenanceActivityFactory
    {
        return MaintenanceActivityFactory::new();
    }

    /**
     * @return BelongsTo<MaintenanceWorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }
}
