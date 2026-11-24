<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\Forecast;

/**
 * @extends Factory<Forecast>
 */
final class ForecastFactory extends Factory
{
    protected $model = Forecast::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'period' => '2027-Q1',
            'currency' => 'EUR',
            'pipeline_amount' => 150000.00,
            'best_case_amount' => 220000.00,
            'commit_amount' => 180000.00,
            'closed_amount' => 95000.00,
            'weighted_amount' => 145000.00,
            'quota_amount' => 200000.00,
            'attainment_percent' => 47.50,
            'status' => 'draft',
        ];
    }
}
