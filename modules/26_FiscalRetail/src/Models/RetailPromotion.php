<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Models;

use Database\Factories\RetailPromotionFactory;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A POS promotion: percentage or fixed amount off the ticket or one product, or buy X get Y free of one product,
 * limited by period, weekdays, time window and minimum subtotal.
 */
#[UseFactory(RetailPromotionFactory::class)]
final class RetailPromotion extends Model
{
    /** @use HasFactory<RetailPromotionFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    public const array Types = ['percentage', 'fixed', 'buy_x_get_y'];

    protected $table = 'retail_promotions';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'type', 'value', 'product_id', 'buy_quantity', 'get_quantity', 'min_subtotal',
        'starts_at', 'ends_at', 'weekdays', 'time_from', 'time_to', 'priority', 'is_stackable', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'min_subtotal' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'weekdays' => 'array',
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'priority' => 'integer',
            'is_stackable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
