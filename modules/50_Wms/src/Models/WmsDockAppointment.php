<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\Warehouse;

/**
 * A carrier slot on a dock door. Two active appointments never overlap on the same door.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dock_door_id
 * @property string $warehouse_id
 * @property string $carrier_name
 * @property string|null $reference
 * @property string $direction
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $completed_at
 * @property string|null $cross_dock_order_id
 * @property string|null $notes
 */
final class WmsDockAppointment extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Directions = ['inbound', 'outbound'];

    /** @var list<string> */
    public const array Statuses = ['scheduled', 'checked_in', 'completed', 'cancelled', 'no_show'];

    public const string Scheduled = 'scheduled';

    public const string CheckedIn = 'checked_in';

    public const string Completed = 'completed';

    public const string Cancelled = 'cancelled';

    public const string NoShow = 'no_show';

    protected $table = 'wms_dock_appointments';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<WmsDockDoor, $this>
     */
    public function door(): BelongsTo
    {
        return $this->belongsTo(WmsDockDoor::class, 'dock_door_id');
    }
}
