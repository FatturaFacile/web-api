<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lodging booking: booked, checked in (a room is assigned), checked out, cancelled or no-show. Nights are check-in inclusive and check-out exclusive.
 *
 * @property string $id
 * @property string $number
 * @property string $guest_name
 * @property string $room_type_id
 * @property string|null $accommodation_id
 * @property string|null $rate_plan_id
 * @property int $adults
 * @property string $status
 * @property string $room_total
 * @property list<string> $nightly_rates
 * @property string|null $group_block_id
 * @property string|null $invoice_id
 * @property Carbon $check_in_date
 * @property Carbon $check_out_date
 */
final class Stay extends HospitalityRecord
{
    /** @var array<string, list<string>> */
    public const array Transitions = [
        'booked' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['checked_out'],
        'checked_out' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    /** Statuses that hold a room for their nights. */
    public const array Holding = ['booked', 'checked_in'];

    protected $table = 'hosp_stays';

    /** @var list<string> */
    protected $appends = ['folio_balance'];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'adults' => 'integer',
            'room_total' => 'decimal:2',
            'nightly_rates' => 'array',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    /**
     * @return BelongsTo<Accommodation, $this>
     */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class, 'accommodation_id');
    }

    /**
     * @return HasMany<FolioEntry, $this>
     */
    public function folioEntries(): HasMany
    {
        return $this->hasMany(FolioEntry::class, 'stay_id')->orderBy('posted_on')->orderBy('created_at');
    }

    /**
     * Charges minus payments, voided entries excluded, as a 2-decimal string. Positive means the guest still owes.
     */
    public function getFolioBalanceAttribute(): string
    {
        $cents = 0;
        foreach ($this->folioEntries()->whereNull('voided_at')->get() as $entry) {
            $signed = (int) round(((float) $entry->amount) * 100);
            $cents += $entry->entry_type === 'charge' ? $signed : -$signed;
        }

        return number_format($cents / 100, 2, '.', '');
    }
}
