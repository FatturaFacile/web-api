<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Employee;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;

/**
 * Licences, professional certificates and rolling driving and rest counters (minutes) of a driver.
 *
 * @property string $driver_employee_id
 * @property string|null $driving_license_number
 * @property list<string>|null $driving_license_categories
 * @property Carbon|null $driving_license_expiry_date
 * @property string|null $cqc_persone_number_it
 * @property Carbon|null $cqc_persone_expiry_date
 * @property string|null $czv_certificate_number_ch
 * @property Carbon|null $czv_expiry_date
 * @property string|null $tachograph_card_number
 * @property Carbon|null $tachograph_card_expiry_date
 * @property int $daily_driving_minutes_used
 * @property int $extended_10h_days_used_this_week
 * @property int $continuous_driving_minutes_since_break
 * @property int $weekly_driving_minutes_used
 * @property int $biweekly_driving_minutes_used
 * @property int $last_daily_rest_duration_minutes
 * @property int $reduced_9h_rests_used_between_weekly_rests
 * @property Carbon|null $last_weekly_rest_ended_at
 * @property bool $available_for_next_trip
 * @property Carbon|null $next_mandatory_break_or_rest_at
 * @property Carbon|null $counters_updated_at
 */
final class DriverComplianceState extends BaseEnterpriseModel
{
    protected $table = 'ptr_driver_compliance_states';

    protected function casts(): array
    {
        return [
            'driving_license_categories' => 'array',
            'driving_license_expiry_date' => 'date',
            'cqc_persone_expiry_date' => 'date',
            'czv_expiry_date' => 'date',
            'tachograph_card_expiry_date' => 'date',
            'daily_driving_minutes_used' => 'integer',
            'extended_10h_days_used_this_week' => 'integer',
            'continuous_driving_minutes_since_break' => 'integer',
            'weekly_driving_minutes_used' => 'integer',
            'biweekly_driving_minutes_used' => 'integer',
            'last_daily_rest_duration_minutes' => 'integer',
            'reduced_9h_rests_used_between_weekly_rests' => 'integer',
            'last_weekly_rest_ended_at' => 'datetime',
            'available_for_next_trip' => 'boolean',
            'next_mandatory_break_or_rest_at' => 'datetime',
            'counters_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'driver_employee_id');
    }

    public function counters(): DriverCounters
    {
        return new DriverCounters(
            $this->daily_driving_minutes_used,
            $this->extended_10h_days_used_this_week,
            $this->continuous_driving_minutes_since_break,
            $this->weekly_driving_minutes_used,
            $this->biweekly_driving_minutes_used,
            $this->last_daily_rest_duration_minutes,
            $this->reduced_9h_rests_used_between_weekly_rests,
            $this->last_weekly_rest_ended_at?->toDateTimeImmutable(),
        );
    }
}
