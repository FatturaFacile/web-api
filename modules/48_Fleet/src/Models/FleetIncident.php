<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A traffic fine, accident or damage of a vehicle, attributed to the driver and tracked until paid.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string|null $employee_id
 * @property string $kind
 * @property Carbon $occurred_on
 * @property string|null $reference
 * @property string $description
 * @property string|null $location
 * @property string $amount
 * @property string $currency
 * @property string $payment_status
 * @property Carbon|null $due_on
 * @property Carbon|null $paid_on
 * @property string|null $notes
 */
final class FleetIncident extends BaseEnterpriseModel
{
    public const string Unpaid = 'unpaid';

    public const string Paid = 'paid';

    public const string Contested = 'contested';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array PaymentStatuses = [self::Unpaid, self::Paid, self::Contested, self::Cancelled];

    /** @var list<string> */
    public const array Kinds = ['fine', 'accident', 'damage', 'other'];

    protected $table = 'fleet_incidents';

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<FleetVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }
}
