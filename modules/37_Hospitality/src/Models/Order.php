<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The order of a table, from seating to the last payment.
 *
 * @property string $id
 * @property string $table_id
 * @property int $covers
 * @property string $status
 */
final class Order extends HospitalityRecord
{
    protected $table = 'hosp_orders';

    protected function casts(): array
    {
        return ['covers' => 'integer', 'closed_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id')->orderBy('course')->orderBy('created_at');
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class, 'order_id');
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }
}
