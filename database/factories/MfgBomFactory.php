<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;

/**
 * @extends Factory<MfgBom>
 */
final class MfgBomFactory extends Factory
{
    protected $model = MfgBom::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'bom_number' => 'BOM-'.strtoupper((string) Str::random(8)),
            'mfg_item_id' => MfgItem::factory(),
            'name' => fake()->words(3, true),
            'revision' => 'A',
            'version' => 1,
            'bom_type' => 'standard',
            'status' => 'active',
            'base_quantity' => 1.0000,
            'uom' => 'PCS',
            'effective_from' => now()->toDateString(),
            'is_locked' => false,
        ];
    }
}
