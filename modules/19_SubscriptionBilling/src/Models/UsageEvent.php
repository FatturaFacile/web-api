<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A metered usage record of a subscription item.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $subscription_id
 * @property string $subscription_item_id
 * @property string $metric_code
 * @property string $idempotency_key
 * @property Carbon|null $event_timestamp
 * @property bool $is_processed
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
final class UsageEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sub_usage_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'event_timestamp' => 'datetime',
            'is_processed' => 'boolean',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function newFactory(): ?Factory
    {
        $factoryName = 'Database\\Factories\\UsageEventFactory';
        if (class_exists($factoryName)) {
            return $factoryName::new();
        }

        return null;
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<SubscriptionItem, $this>
     */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'subscription_item_id');
    }
}
