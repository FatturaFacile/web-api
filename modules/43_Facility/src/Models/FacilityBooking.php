<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A booking of a space for an interval: booked, checked in, completed, cancelled or released as a no-show. Only booked
 * and checked-in bookings occupy the space.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $space_id
 * @property string|null $series_id
 * @property string|null $user_id
 * @property string|null $title
 * @property int|null $attendees
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $released_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $created_by
 */
final class FacilityBooking extends BaseEnterpriseModel
{
    public const string Booked = 'booked';

    public const string CheckedIn = 'checked_in';

    public const string Completed = 'completed';

    public const string Cancelled = 'cancelled';

    public const string NoShow = 'no_show';

    /** @var list<string> */
    public const array Statuses = [self::Booked, self::CheckedIn, self::Completed, self::Cancelled, self::NoShow];

    /** @var list<string> Statuses that occupy the space. */
    public const array OccupyingStatuses = [self::Booked, self::CheckedIn];

    protected $table = 'fac_bookings';

    protected function casts(): array
    {
        return [
            'attendees' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'released_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function occupiesSpace(): bool
    {
        return in_array($this->status, self::OccupyingStatuses, true);
    }
}
