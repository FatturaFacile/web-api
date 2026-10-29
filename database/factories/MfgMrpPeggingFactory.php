<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgMrpPegging;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;

/**
 * @extends Factory<MfgMrpPegging>
 */
final class MfgMrpPeggingFactory extends Factory
{
    protected $model = MfgMrpPegging::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'planned_order_id' => MfgMrpPlannedOrder::factory(),
            'demand_type' => 'safety_stock',
            'pegged_quantity' => 50.0000,
        ];
    }
}
