<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgMrpRun;

/**
 * @extends Factory<MfgMrpRun>
 */
final class MfgMrpRunFactory extends Factory
{
    protected $model = MfgMrpRun::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'run_number' => 'MRP-'.strtoupper((string) Str::random(8)),
            'run_type' => 'regenerative',
            'status' => 'completed',
            'horizon_start_date' => now()->toDateString(),
            'horizon_end_date' => now()->addMonths(3)->toDateString(),
            'total_items_processed' => fake()->numberBetween(10, 50),
            'total_planned_orders' => fake()->numberBetween(5, 20),
            'total_shortages' => fake()->numberBetween(2, 10),
            'executed_at' => now(),
        ];
    }
}
