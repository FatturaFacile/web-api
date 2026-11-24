<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\Subscription;

class RevenueContractFactory extends Factory
{
    protected $model = RevenueContract::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_number' => 'REV-CTR-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'subscription_id' => Subscription::factory(),
            'total_transaction_price' => 1440.00,
            'currency' => 'EUR',
            'inception_date' => now()->toDateString(),
            'status' => 'active',
        ];
    }
}
