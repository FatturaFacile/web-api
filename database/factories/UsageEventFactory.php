<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageEvent;

class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'subscription_item_id' => SubscriptionItem::factory(),
            'metric_code' => 'API_CALLS',
            'idempotency_key' => 'IDEMP-'.strtoupper(Str::random(12)),
            'quantity' => fake()->randomFloat(2, 50, 500),
            'event_timestamp' => now(),
            'is_processed' => false,
        ];
    }
}
