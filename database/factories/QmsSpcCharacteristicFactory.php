<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Quality\Models\QmsSpcCharacteristic;

/**
 * @extends Factory<QmsSpcCharacteristic>
 */
final class QmsSpcCharacteristicFactory extends Factory
{
    protected $model = QmsSpcCharacteristic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'CH-'.fake()->unique()->numerify('####'),
            'name' => fake()->words(2, true),
            'lower_spec_limit' => '9.00000',
            'upper_spec_limit' => '11.00000',
            'subgroup_size' => 5,
            'status' => 'active',
        ];
    }
}
