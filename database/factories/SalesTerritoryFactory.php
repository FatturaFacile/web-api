<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\CRM\Models\SalesTerritory;

/**
 * @extends Factory<SalesTerritory>
 */
final class SalesTerritoryFactory extends Factory
{
    protected $model = SalesTerritory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'T-'.strtoupper(Str::random(5)),
            'name' => fake()->city().' territory',
            'criteria' => ['countries' => ['IT']],
            'priority' => 100,
            'is_active' => true,
        ];
    }
}
