<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Database\Factories\MaintenanceActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class MaintenanceActivity extends Model
{
    /** @use HasFactory<MaintenanceActivityFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'eam_maintenance_activities';

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
