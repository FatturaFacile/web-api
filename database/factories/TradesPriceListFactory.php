<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ConstructionTrades\Models\PriceList;

/**
 * @extends Factory<PriceList>
 */
class TradesPriceListFactory extends Factory
{
    protected $model = PriceList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Listino '.fake()->word(),
            'source' => 'own',
            'currency' => 'CHF',
            'languages' => ['it', 'de'],
            'is_active' => true,
        ];
    }
}
