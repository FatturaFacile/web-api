<?php

declare(strict_types=1);

namespace Modules\Wms\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Warehouse;

/**
 * A dock door of a warehouse where carriers load or unload. Appointments are booked per door.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $warehouse_id
 * @property string $code
 * @property string|null $name
 * @property string $direction
 * @property bool $is_active
 * @property string|null $notes
 */
final class WmsDockDoor extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Directions = ['inbound', 'outbound', 'both'];

    public const string Inbound = 'inbound';

    public const string Outbound = 'outbound';

    public const string Both = 'both';

    protected $table = 'wms_dock_doors';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
