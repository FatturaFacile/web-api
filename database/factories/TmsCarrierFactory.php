<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsCarrier;

/**
 * @extends Factory<TmsCarrier>
 */
final class TmsCarrierFactory extends Factory
{
    protected $model = TmsCarrier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->bothify('??-####'),
            'name' => fake()->unique()->bothify('??-####'),
            'mode' => 'road',
            'currency' => 'EUR',
            'is_active' => true,
        ];
    }
}
