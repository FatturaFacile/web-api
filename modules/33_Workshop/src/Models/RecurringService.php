<?php

declare(strict_types=1);

namespace Modules\Workshop\Models;

use App\Models\Subscription;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service billed again at every period (yearly tyre storage, a maintenance plan). The billing itself is a subscription
 * of the sales module, run by the subscriptions:process command through ConvertToInvoice.
 */
final class RecurringService extends WorkshopRecord
{
    /** @var list<string> */
    public const array Types = ['tyre_storage', 'maintenance_plan', 'other'];

    protected $table = 'workshop_recurring_services';

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
