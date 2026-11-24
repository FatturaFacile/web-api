<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Campaign;

/**
 * @extends Factory<Campaign>
 */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CMP-'.strtoupper(Str::random(6)),
            'name' => fake()->catchPhrase().' Marketing Campaign',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(60)->toDateString(),
            'budgeted_cost' => 15000.00,
            'actual_cost' => 12500.00,
            'expected_revenue' => 75000.00,
            'actual_revenue' => 82000.00,
            'utm_source' => 'linkedin',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'q1_erp_launch',
        ];
    }
}
