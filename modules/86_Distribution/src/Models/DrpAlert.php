<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Distribution\Domain\Enums\AlertType;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

/**
 * An actionable alert raised by a DRP run: stockout risk, excess stock or a late inbound transfer.
 *
 * @property string $drp_run_id
 * @property AlertType $alert_type
 * @property string $severity
 * @property string|null $warehouse_id
 * @property string|null $stock_item_id
 * @property int|null $bucket_index
 * @property string|null $transfer_order_id
 * @property array<string, mixed>|null $params
 */
final class DrpAlert extends BaseEnterpriseModel
{
    protected $table = 'distribution_drp_alerts';

    protected function casts(): array
    {
        return [
            'alert_type' => AlertType::class,
            'bucket_index' => 'integer',
            'params' => 'array',
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
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
