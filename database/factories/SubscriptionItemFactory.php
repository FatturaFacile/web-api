<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;

class SubscriptionItemFactory extends Factory
{
    protected $model = SubscriptionItem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'item_type' => 'plan',
            'description' => 'Base Subscription License',
            'quantity' => 1.0,
            'unit_price' => 120.00,
            'discount_percentage' => 0.00,
            'subtotal_recurring' => 120.00,
        ];
    }
}
