<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartsConsumption extends BaseEnterpriseModel
{
    protected $table = 'esm_parts_consumptions';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'serial_numbers' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function quantityConsumed(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['quantity'] ?? $value ?? 0.0),
            set: fn ($value) => ['quantity' => $value]
        );
    }

    protected function itemNumber(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $attributes['lot_number'] ?? $meta['item_number'] ?? '';
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['item_number'] = (string) $value;

                return ['lot_number' => $value, 'metadata' => json_encode($meta)];
            }
        );
    }

    protected function description(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['description'] ?? '';
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['description'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function unitPrice(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return (float) ($meta['unit_price'] ?? 0.0);
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['unit_price'] = (float) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function totalPrice(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return (float) ($meta['total_price'] ?? 0.0);
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['total_price'] = (float) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
