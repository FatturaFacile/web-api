<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgRouting;

/**
 * @extends Factory<MfgProductionOrder>
 */
final class MfgProductionOrderFactory extends Factory
{
    protected $model = MfgProductionOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'order_number' => 'MO-'.strtoupper((string) Str::random(8)),
            'mfg_item_id' => MfgItem::factory(),
            'bom_id' => MfgBom::factory(),
            'routing_id' => MfgRouting::factory(),
            'status' => 'planned',
            'target_quantity' => fake()->randomFloat(4, 10, 100),
            'completed_quantity' => 0.0000,
            'scrapped_quantity' => 0.0000,
            'uom' => 'PCS',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
            'standard_cost_total' => fake()->randomFloat(4, 500, 5000),
            'actual_cost_total' => 0.0000,
            'variance_total' => 0.0000,
            'lock_version' => 1,
            'is_locked' => false,
        ];
    }

    public function planned(): self
    {
        return $this->state(fn () => ['status' => 'planned']);
    }

    public function released(): self
    {
        return $this->state(fn () => ['status' => 'released']);
    }

    public function scheduled(): self
    {
        return $this->state(fn () => ['status' => 'scheduled']);
    }

    public function inProgress(): self
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'actual_start_date' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_quantity' => $attributes['target_quantity'] ?? 50.0,
            'actual_start_date' => now()->subDays(2),
            'actual_end_date' => now(),
            'actual_cost_total' => $attributes['standard_cost_total'] ?? 2500.0,
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'completed_quantity' => $attributes['target_quantity'] ?? 50.0,
            'actual_start_date' => now()->subDays(3),
            'actual_end_date' => now()->subDay(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function reworked(): self
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'notes' => 'Undergoing rework cycle due to tolerance variation.',
        ]);
    }

    public function scrapped(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'scrapped_quantity' => $attributes['target_quantity'] ?? 20.0,
            'notes' => 'Entire production batch scrapped due to material contamination.',
        ]);
    }
}
