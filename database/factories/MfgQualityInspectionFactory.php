<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityPlan;

/**
 * @extends Factory<MfgQualityInspection>
 */
final class MfgQualityInspectionFactory extends Factory
{
    protected $model = MfgQualityInspection::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'inspection_number' => 'INS-'.strtoupper((string) Str::random(8)),
            'quality_plan_id' => MfgQualityPlan::factory(),
            'production_order_id' => MfgProductionOrder::factory(),
            'sample_size' => 5,
            'defect_count' => 0,
            'status' => 'passed',
            'inspected_at' => now(),
        ];
    }

    public function passed(): self
    {
        return $this->state(fn () => [
            'status' => 'passed',
            'defect_count' => 0,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'defect_count' => 3,
        ]);
    }

    public function conditionalPass(): self
    {
        return $this->state(fn () => [
            'status' => 'conditional_pass',
            'defect_count' => 1,
        ]);
    }
}
