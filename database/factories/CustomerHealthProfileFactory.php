<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\CustomerHealthProfile;

/**
 * @extends Factory<CustomerHealthProfile>
 */
final class CustomerHealthProfileFactory extends Factory
{
    protected $model = CustomerHealthProfile::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'account_id' => Account::factory(),
            'activity_score' => 80.00,
            'engagement_score' => 75.00,
            'revenue_score' => 90.00,
            'risk_score' => 15.00,
            'churn_risk_indicator' => 'low',
            'health_index' => 84.75,
            'last_interaction_at' => now()->subDays(3),
            'open_receivable_amount' => 12500.00,
            'overdue_receivable_amount' => 0.00,
        ];
    }
}
