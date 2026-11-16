<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring booking: the bookings it generated are normal bookings that point back to the series.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $space_id
 * @property string|null $user_id
 * @property string|null $title
 * @property string $recurrence
 * @property list<int> $weekdays
 * @property Carbon $start_date
 * @property Carbon $until_date
 * @property string $start_time
 * @property string $end_time
 * @property string $status
 * @property int $occurrences
 * @property string|null $created_by
 */
final class FacilityBookingSeries extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Recurrences = ['daily', 'weekly'];

    protected $table = 'fac_booking_series';

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'start_date' => 'date',
            'until_date' => 'date',
            'occurrences' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FacilitySpace, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(FacilitySpace::class, 'space_id');
    }

    /**
     * @return HasMany<FacilityBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(FacilityBooking::class, 'series_id');
    }
}
