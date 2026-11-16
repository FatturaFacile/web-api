<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsCarrier;
use Modules\Transportation\Models\TmsTariffTable;

/**
 * @extends Factory<TmsTariffTable>
 */
final class TmsTariffTableFactory extends Factory
{
    protected $model = TmsTariffTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'carrier_id' => TmsCarrier::factory(),
            'name' => fake()->unique()->bothify('??-####'),
            'currency' => 'EUR',
            'valid_from' => '2027-01-15',
            'rate_basis' => 'weight',
            'fuel_surcharge_percent' => '0',
            'minimum_charge' => '0',
            'is_active' => true,
        ];
    }
}
