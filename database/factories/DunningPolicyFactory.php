<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Models\DunningPolicy;

class DunningPolicyFactory extends Factory
{
    protected $model = DunningPolicy::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'policy_name' => 'Standard SaaS Dunning Policy',
            'is_default' => true,
            'soft_reminder_days' => 3,
            'formal_notice_days' => 10,
            'suspension_days' => 25,
            'termination_days' => 45,
        ];
    }
}
