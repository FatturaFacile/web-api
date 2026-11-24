<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Planning\Models\PlanningResource;

/**
 * @extends Factory<PlanningResource>
 */
final class PlanningResourceFactory extends Factory
{
    protected $model = PlanningResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'RES-'.Str::upper(Str::random(4)),
            'name' => 'Assembly line',
            'unit' => 'hours',
            'default_capacity' => '160.000',
            'is_active' => true,
        ];
    }
}
