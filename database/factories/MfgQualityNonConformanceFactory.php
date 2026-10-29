<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;

/**
 * @extends Factory<MfgQualityNonConformance>
 */
final class MfgQualityNonConformanceFactory extends Factory
{
    protected $model = MfgQualityNonConformance::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'nc_number' => 'NCR-'.strtoupper((string) Str::random(8)),
            'quality_inspection_id' => MfgQualityInspection::factory(),
            'severity' => 'major',
            'defect_type' => 'Dimensional Deviation',
            'description' => fake()->paragraph(),
            'disposition' => 'rework',
            'status' => 'open',
        ];
    }
}
