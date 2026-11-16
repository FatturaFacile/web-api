<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsTrip;

/**
 * @extends Factory<TmsTrip>
 */
final class TmsTripFactory extends Factory
{
    protected $model = TmsTrip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'number' => 'TR-2027-'.fake()->unique()->numerify('####'),
            'status' => 'planned',
            'currency' => 'EUR',
            'total_weight_kg' => '0',
            'total_volume_cbm' => '0',
        ];
    }
}
