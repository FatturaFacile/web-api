<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Sustainability\Models\EsgEsrsDatapoint;

/**
 * @extends Factory<EsgEsrsDatapoint>
 */
final class EsgEsrsDatapointFactory extends Factory
{
    protected $model = EsgEsrsDatapoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'E1-'.fake()->unique()->numerify('######'),
            'standard' => 'E1',
            'disclosure' => 'E1-6',
            'name' => fake()->sentence(4),
            'data_type' => 'number',
            'unit' => 'tCO2e',
            'is_mandatory' => true,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
