<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UsageAggregation extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_usage_aggregations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_consumed' => 'decimal:4',
            'included_allowance' => 'decimal:4',
            'billable_overage' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'billable_amount' => 'decimal:2',
            'is_billed' => 'boolean',
            'billed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\UsageAggregationFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'subscription_item_id');
    }
}
