<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgReportingPeriod;

/**
 * @extends Factory<EsgReportingPeriod>
 */
final class EsgReportingPeriodFactory extends Factory
{
    protected $model = EsgReportingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->numberBetween(2020, 2030);

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'name' => "FY {$year}",
            'starts_on' => "{$year}-01-01",
            'ends_on' => "{$year}-12-31",
            'status' => EsgReportingPeriod::Open,
            'currency' => 'EUR',
        ];
    }
}
