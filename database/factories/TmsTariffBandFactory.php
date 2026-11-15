<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Transportation\Models\TmsTariffBand;
use Modules\Transportation\Models\TmsTariffTable;

/**
 * @extends Factory<TmsTariffBand>
 */
final class TmsTariffBandFactory extends Factory
{
    protected $model = TmsTariffBand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'tariff_table_id' => TmsTariffTable::factory(),
            'weight_from_kg' => '0',
            'distance_from_km' => '0',
            'fixed_amount' => '0',
            'price_per_kg' => '0',
            'price_per_km' => '0',
        ];
    }
}
