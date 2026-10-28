<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockItemCategory;

/**
 * @extends Factory<StockItemCategory>
 */
class StockItemCategoryFactory extends Factory
{
    protected $model = StockItemCategory::class;

    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => 'CAT-'.strtoupper(fake()->unique()->bothify('???')),
            'name' => fake()->words(2, true).' Category',
            'valuation_method' => 'fifo',
        ];
    }
}
