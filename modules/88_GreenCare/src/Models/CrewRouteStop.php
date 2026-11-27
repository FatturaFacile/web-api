<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Enums\BillingStatus;

/**
 * One visit of a crew to a site, with the check-in/out, the hours, the materials and the photos of the intervention.
 *
 * @property string $route_schedule_id
 * @property string $site_id
 * @property string|null $contract_id
 * @property int $stop_order
 * @property list<string>|null $planned_activities
 * @property int $estimated_duration_minutes
 * @property Carbon|null $actual_checkin_at
 * @property Carbon|null $actual_checkout_at
 * @property array<string, mixed>|null $checkin_gps
 * @property array<string, mixed>|null $checkout_gps
 * @property array<string, mixed>|null $crew_hours_logged
 * @property array<int, array<string, mixed>>|null $machinery_hours_logged
 * @property array<int, array<string, mixed>>|null $materials_and_plants_used
 * @property list<string>|null $before_photos
 * @property list<string>|null $after_photos
 * @property bool $counts_toward_contract_passage
 * @property bool $is_extra_passage
 * @property int|null $passage_number
 * @property string $extra_passage_amount
 * @property BillingStatus $billing_status
 * @property string|null $invoice_id
 */
final class CrewRouteStop extends BaseEnterpriseModel
{
    protected $table = 'crew_route_stops';

    protected function casts(): array
    {
        return [
            'stop_order' => 'integer',
            'planned_activities' => 'array',
            'estimated_duration_minutes' => 'integer',
            'actual_checkin_at' => 'datetime',
            'actual_checkout_at' => 'datetime',
            'checkin_gps' => 'array',
            'checkout_gps' => 'array',
            'crew_hours_logged' => 'array',
            'machinery_hours_logged' => 'array',
            'materials_and_plants_used' => 'array',
            'before_photos' => 'array',
            'after_photos' => 'array',
            'counts_toward_contract_passage' => 'boolean',
            'is_extra_passage' => 'boolean',
            'passage_number' => 'integer',
            'extra_passage_amount' => 'decimal:2',
            'billing_status' => BillingStatus::class,
        ];
    }

    /**
     * @return BelongsTo<CrewRouteSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CrewRouteSchedule::class, 'route_schedule_id');
    }

    /**
     * @return BelongsTo<GreenSpaceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(GreenSpaceSite::class, 'site_id');
    }

    /**
     * @return BelongsTo<SeasonalMaintenanceContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(SeasonalMaintenanceContract::class, 'contract_id');
    }

    public function isCompleted(): bool
    {
        return $this->actual_checkout_at !== null;
    }

    public function loggedHours(): float
    {
        return (float) ($this->crew_hours_logged['total'] ?? 0);
    }
}
