<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningItemResourceRate;
use Modules\Planning\Models\PlanningResource;

/**
 * @extends Factory<PlanningItemResourceRate>
 */
final class PlanningItemResourceRateFactory extends Factory
{
    protected $model = PlanningItemResourceRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'resource_id' => PlanningResource::factory(),
            'item_code' => 'ITEM-'.Str::upper(Str::random(4)),
            'load_per_unit' => '0.50000',
        ];
    }
}
