<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;

/**
 * @extends Factory<MfgMrpPlannedOrder>
 */
final class MfgMrpPlannedOrderFactory extends Factory
{
    protected $model = MfgMrpPlannedOrder::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'planned_order_number' => 'PLN-'.strtoupper((string) Str::random(8)),
            'mrp_run_id' => MfgMrpRun::factory(),
            'mfg_item_id' => MfgItem::factory(),
            'order_type' => fake()->randomElement(['make', 'buy']),
            'quantity' => fake()->randomFloat(4, 10, 100),
            'uom' => 'PCS',
            'start_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'planned',
        ];
    }
}
