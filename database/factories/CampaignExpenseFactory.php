<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\CampaignExpense;

/**
 * @extends Factory<CampaignExpense>
 */
final class CampaignExpenseFactory extends Factory
{
    protected $model = CampaignExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'campaign_id' => static fn (array $attributes): string => Campaign::create([
                'tenant_id' => $attributes['tenant_id'],
                'code' => strtoupper(fake()->unique()->bothify('RAC-####')),
                'name' => 'Raccolta fondi nuovo pulmino',
                'kind' => Campaign::Occasional,
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-12-31',
                'target_amount' => '12000.00',
                'status' => 'active',
            ])->id,
            'incurred_on' => fake()->dateTimeBetween('2026-10-01', '2026-12-31')->format('Y-m-d'),
            'description' => 'Stampa volantini e locandine',
            'amount' => number_format(fake()->randomFloat(2, 50, 1500), 2, '.', ''),
            'payment_method' => 'bank',
        ];
    }
}
