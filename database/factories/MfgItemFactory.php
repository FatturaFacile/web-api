<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;

/**
 * @extends Factory<MfgItem>
 */
final class MfgItemFactory extends Factory
{
    protected $model = MfgItem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'item_code' => 'ITM-'.strtoupper((string) Str::random(8)),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'item_type' => fake()->randomElement(['raw_material', 'subassembly', 'finished_good', 'phantom']),
            'uom' => 'PCS',
            'standard_cost' => fake()->randomFloat(4, 5, 250),
            'current_cost' => fake()->randomFloat(4, 5, 250),
            'scrap_rate' => 0.00,
            'safety_stock' => fake()->randomFloat(4, 10, 50),
            'min_lot_size' => 1.0000,
            'lead_time_days' => fake()->numberBetween(1, 14),
            'is_active' => true,
        ];
    }

    public function rawMaterial(): self
    {
        return $this->state(fn () => ['item_type' => 'raw_material']);
    }

    public function finishedGood(): self
    {
        return $this->state(fn () => ['item_type' => 'finished_good']);
    }

    public function subassembly(): self
    {
        return $this->state(fn () => ['item_type' => 'subassembly']);
    }

    public function phantom(): self
    {
        return $this->state(fn () => ['item_type' => 'phantom']);
    }
}
