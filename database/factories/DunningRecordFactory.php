<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\DunningRecord;
use Modules\SubscriptionBilling\Models\Subscription;

class DunningRecordFactory extends Factory
{
    protected $model = DunningRecord::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'invoice_id' => (string) Str::uuid7(),
            'dunning_level' => 1,
            'status' => 'active',
            'action_taken' => 'Email Soft Reminder Dispatched',
        ];
    }
}
