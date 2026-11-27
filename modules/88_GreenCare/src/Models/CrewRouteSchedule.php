<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Enums\RouteStatus;
use Modules\GreenCare\Domain\Enums\WeatherAlertStatus;

/**
 * The day of a crew: ordered stops, vehicle, equipment and the weather outlook.
 *
 * @property Carbon $schedule_date
 * @property string $crew_team_id
 * @property string|null $assigned_vehicle_plate
 * @property string|null $assigned_trailer_plate
 * @property array<string, mixed>|null $equipment_list
 * @property WeatherAlertStatus $weather_alert_status
 * @property array<string, mixed>|null $weather_forecast_summary
 * @property array<string, mixed>|null $route_optimization_metadata
 * @property RouteStatus $status
 */
final class CrewRouteSchedule extends BaseEnterpriseModel
{
    protected $table = 'crew_route_schedules';

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'equipment_list' => 'array',
            'weather_alert_status' => WeatherAlertStatus::class,
            'weather_forecast_summary' => 'array',
            'route_optimization_metadata' => 'array',
            'status' => RouteStatus::class,
        ];
    }

    /**
     * @return BelongsTo<CrewTeam, $this>
     */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(CrewTeam::class, 'crew_team_id');
    }

    /**
     * @return HasMany<CrewRouteStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(CrewRouteStop::class, 'route_schedule_id')->orderBy('stop_order');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [RouteStatus::Completed, RouteStatus::InProgress], true);
    }
}
