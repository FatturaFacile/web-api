<?php

declare(strict_types=1);

namespace Modules\Fleet\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A compliance deadline of a vehicle: road tax, insurance, inspection. Renewing it closes it and opens the next one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property string $deadline_type
 * @property string|null $provider
 * @property string|null $reference
 * @property Carbon $expires_on
 * @property string $cost
 * @property string $currency
 * @property string $status
 * @property Carbon|null $renewed_at
 * @property string|null $notes
 */
final class FleetDeadline extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string Renewed = 'renewed';

    /** @var list<string> */
    public const array Statuses = [self::Open, self::Renewed];

    /** @var list<string> */
    public const array Types = ['road_tax', 'insurance', 'inspection', 'other'];

    protected $table = 'fleet_deadlines';

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'cost' => 'decimal:2',
            'renewed_at' => 'datetime',
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
