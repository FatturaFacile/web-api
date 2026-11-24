<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;

class SubscriptionVersionFactory extends Factory
{
    protected $model = SubscriptionVersion::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'version_number' => 1,
            'effective_date' => now()->toDateString(),
            'change_type' => 'initial',
            'mrr_before' => 0.00,
            'mrr_after' => 120.00,
            'mrr_delta' => 120.00,
            'proration_amount' => 0.00,
            'snapshot_payload' => ['plan' => 'Enterprise Suite'],
            'change_reason' => 'Initial subscription creation',
        ];
    }
}
