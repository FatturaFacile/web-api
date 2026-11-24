<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgEsrsDatapoint;
use Modules\Sustainability\Models\EsgEsrsDatapointValue;
use Modules\Sustainability\Models\EsgReportingPeriod;

/**
 * @extends Factory<EsgEsrsDatapointValue>
 */
final class EsgEsrsDatapointValueFactory extends Factory
{
    protected $model = EsgEsrsDatapointValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'datapoint_id' => EsgEsrsDatapoint::factory(),
            'reporting_period_id' => EsgReportingPeriod::factory(),
            'status' => EsgEsrsDatapointValue::Draft,
            'value_number' => fake()->randomFloat(4, 1, 1000),
            'source' => 'manual',
        ];
    }
}
