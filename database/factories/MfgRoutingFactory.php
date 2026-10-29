<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgRouting;

/**
 * @extends Factory<MfgRouting>
 */
final class MfgRoutingFactory extends Factory
{
    protected $model = MfgRouting::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'routing_number' => 'RTG-'.strtoupper((string) Str::random(8)),
            'mfg_item_id' => MfgItem::factory(),
            'name' => fake()->words(3, true),
            'revision' => 'A',
            'status' => 'active',
            'is_default' => true,
        ];
    }
}
