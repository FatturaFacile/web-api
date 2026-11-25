<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Daily or recurring route plan with ordered customer stops.
 *
 * @property string $van_warehouse_id
 * @property string|null $van_load_manifest_id
 * @property string $route_code
 * @property string $name
 * @property Carbon $plan_date
 * @property int $weekday_iso
 * @property bool $is_recurring
 * @property string|null $salesperson_user_id
 * @property string|null $salesperson_name
 * @property string|null $territory_zone
 * @property string $country
 * @property string $status
 * @property array<int, array<string, mixed>> $stops
 * @property int $total_stops
 * @property int $completed_stops
 * @property int $sync_version
 * @property string|null $notes
 */
final class FieldRoutePlan extends BaseEnterpriseModel
{
    protected $table = 'field_route_plans';

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'weekday_iso' => 'integer',
            'is_recurring' => 'boolean',
            'stops' => 'array',
            'total_stops' => 'integer',
            'completed_stops' => 'integer',
            'sync_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VanWarehouse, $this>
     */
    public function vanWarehouse(): BelongsTo
    {
        return $this->belongsTo(VanWarehouse::class, 'van_warehouse_id');
    }

    /**
     * @return HasMany<VanVisitOutcome, $this>
     */
    public function visitOutcomes(): HasMany
    {
        return $this->hasMany(VanVisitOutcome::class, 'field_route_plan_id')->orderBy('stop_sequence');
    }
}
