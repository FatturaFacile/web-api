<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\Warehouse;

/**
 * Goods that go from an inbound receipt to an outbound shipment without being stored. Lines carry the quantity received and the quantity matched to the outbound side.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $number
 * @property string $inbound_reference
 * @property string $outbound_reference
 * @property string|null $inbound_door_id
 * @property string|null $outbound_door_id
 * @property string $status
 * @property Carbon|null $planned_inbound_at
 * @property Carbon|null $planned_outbound_at
 * @property Carbon|null $received_at
 * @property Carbon|null $shipped_at
 * @property string|null $notes
 */
final class WmsCrossDockOrder extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['planned', 'received', 'shipped', 'cancelled'];

    public const string Planned = 'planned';

    public const string Received = 'received';

    public const string Shipped = 'shipped';

    public const string Cancelled = 'cancelled';

    protected $table = 'wms_cross_dock_orders';

    protected function casts(): array
    {
        return [
            'planned_inbound_at' => 'datetime',
            'planned_outbound_at' => 'datetime',
            'received_at' => 'datetime',
            'shipped_at' => 'datetime',
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
     * @return HasMany<WmsCrossDockLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WmsCrossDockLine::class, 'cross_dock_order_id');
    }

    /**
     * How much of the inbound quantity is matched to the outbound side (needs the lines loaded).
     */
    public function matchStatus(): string
    {
        $outbound = $this->lines->sum(static fn (WmsCrossDockLine $line): float => (float) $line->outbound_quantity);

        if ($outbound <= 0.0) {
            return 'unmatched';
        }

        $received = in_array($this->status, [self::Received, self::Shipped], true);

        return $this->lines->every(static fn (WmsCrossDockLine $line): bool => bccomp((string) $line->outbound_quantity, (string) ($received ? $line->received_quantity : $line->inbound_quantity), 4) >= 0) ? 'matched' : 'partial';
    }
}
