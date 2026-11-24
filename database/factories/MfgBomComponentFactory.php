<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgItem;

/**
 * @extends Factory<MfgBomComponent>
 */
final class MfgBomComponentFactory extends Factory
{
    protected $model = MfgBomComponent::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'bom_id' => MfgBom::factory(),
            'component_item_id' => MfgItem::factory()->rawMaterial(),
            'sequence' => 10,
            'quantity_per' => fake()->randomFloat(4, 1, 5),
            'uom' => 'PCS',
            'scrap_percentage' => 0.00,
            'yield_percentage' => 100.00,
            'is_phantom' => false,
        ];
    }
}
