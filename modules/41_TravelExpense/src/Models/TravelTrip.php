<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A business trip of an employee: the request, its approval, the advance and, once the trip is over, the
 * reconciliation of the advance against the per-diem days, the mileage claims and the linked expenses.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $number
 * @property string $user_id
 * @property string $purpose
 * @property string $destination_city
 * @property string $destination_country
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $departure_time
 * @property string|null $return_time
 * @property string $currency
 * @property string $rate_jurisdiction
 * @property string $estimated_budget
 * @property string $advance_amount
 * @property Carbon|null $advance_paid_at
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $completed_at
 * @property Carbon|null $reconciled_at
 * @property string|null $reconciled_by
 * @property string|null $reconciled_expenses_total
 * @property string|null $reconciled_per_diem_total
 * @property string|null $reconciled_mileage_total
 * @property string|null $reconciled_advance_total
 * @property string|null $reconciled_balance
 * @property string|null $reconciled_direction
 */
final class TravelTrip extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Submitted = 'submitted';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Completed = 'completed';

    public const string Reconciled = 'reconciled';

    public const array Statuses = [self::Draft, self::Submitted, self::Approved, self::Rejected, self::Completed, self::Reconciled];

    /**
     * Statuses in which the request itself (purpose, dates, budget, advance) can still be changed.
     */
    public const array RequestEditableStatuses = [self::Draft];

    /**
     * Statuses in which itinerary, mileage, per-diem days and linked expenses can be changed.
     */
    public const array WorkingStatuses = [self::Draft, self::Approved, self::Completed];

    public const array Jurisdictions = ['IT', 'CH'];

    protected $table = 'travel_trips';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'advance_paid_at' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'estimated_budget' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'reconciled_expenses_total' => 'decimal:2',
            'reconciled_per_diem_total' => 'decimal:2',
            'reconciled_mileage_total' => 'decimal:2',
            'reconciled_advance_total' => 'decimal:2',
            'reconciled_balance' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<TravelLeg, $this>
     */
    public function legs(): HasMany
    {
        return $this->hasMany(TravelLeg::class, 'trip_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<TravelPerDiemDay, $this>
     */
    public function perDiemDays(): HasMany
    {
        return $this->hasMany(TravelPerDiemDay::class, 'trip_id')->orderBy('day');
    }

    /**
     * @return HasMany<TravelMileageClaim, $this>
     */
    public function mileageClaims(): HasMany
    {
        return $this->hasMany(TravelMileageClaim::class, 'trip_id')->orderBy('travel_date');
    }

    /**
     * @return HasMany<TravelTripExpense, $this>
     */
    public function expenseLinks(): HasMany
    {
        return $this->hasMany(TravelTripExpense::class, 'trip_id');
    }

    public function isRequestEditable(): bool
    {
        return in_array($this->status, self::RequestEditableStatuses, true);
    }

    public function isWorking(): bool
    {
        return in_array($this->status, self::WorkingStatuses, true);
    }
}
