<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RepairOrder extends BaseEnterpriseModel
{
    protected $table = 'repair_orders';

    /** The steps a repair may take from each status. */
    public const array TRANSITIONS = [
        'received' => ['diagnosing', 'cancelled'],
        'diagnosing' => ['waiting_parts', 'repairing', 'ready', 'cancelled'],
        'waiting_parts' => ['repairing', 'cancelled'],
        'repairing' => ['waiting_parts', 'ready', 'cancelled'],
        'ready' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    protected function casts(): array
    {
        return [
            'purchase_value' => 'decimal:2',
            'is_under_warranty' => 'boolean',
            'labour_amount' => 'decimal:2',
            'parts_amount' => 'decimal:2',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(StockSerialNumber::class, 'stock_serial_number_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RepairOrderLine::class, 'repair_order_id');
    }
}
