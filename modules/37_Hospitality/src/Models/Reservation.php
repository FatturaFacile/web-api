<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking of a table for a party and an interval. It is booked, then seated (the guests have an open order),
 * completed; or cancelled by the guest or the venue, or a no-show. Instants are stored in UTC.
 *
 * @property string $id
 * @property ?string $table_id
 * @property ?string $order_id
 * @property string $guest_name
 * @property int $party_size
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $status
 */
final class Reservation extends HospitalityRecord
{
    /** @var array<string, list<string>> */
    public const array Transitions = [
        'booked' => ['seated', 'cancelled', 'no_show'],
        'seated' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    /** Statuses that hold the table for their interval. */
    public const array Holding = ['booked', 'seated'];

    protected $table = 'hosp_reservations';

    protected function casts(): array
    {
        return ['party_size' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }
}
