<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;

/**
 * @extends Factory<MfgEngineeringChangeOrder>
 */
final class MfgEngineeringChangeOrderFactory extends Factory
{
    protected $model = MfgEngineeringChangeOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'eco_number' => 'ECO-'.strtoupper((string) Str::random(8)),
            'title' => fake()->sentence(),
            'reason' => fake()->paragraph(),
            'change_type' => 'bom_change',
            'status' => 'draft',
            'effective_date' => now()->toDateString(),
        ];
    }
}
