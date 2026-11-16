<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Models;

use App\Models\Expense;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a trip and an expense of the expenses feature. An expense settles at most one trip.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $trip_id
 * @property string $expense_id
 */
final class TravelTripExpense extends BaseEnterpriseModel
{
    protected $table = 'travel_trip_expenses';

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }
}
