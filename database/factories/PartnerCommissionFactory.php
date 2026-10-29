<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\PartnerCommission;
use Modules\SubscriptionBilling\Models\Subscription;

class PartnerCommissionFactory extends Factory
{
    protected $model = PartnerCommission::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'partner_id' => (string) Str::uuid7(),
            'subscription_id' => Subscription::factory(),
            'commission_rate' => 0.1500,
            'gross_billed_amount' => 1000.00,
            'commission_earned' => 150.00,
            'status' => 'accrued',
        ];
    }
}
