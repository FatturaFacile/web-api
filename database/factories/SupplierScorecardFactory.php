<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierScorecard;

/**
 * @extends Factory<SupplierScorecard>
 */
class SupplierScorecardFactory extends Factory
{
    protected $model = SupplierScorecard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        $supplier = Supplier::first() ?? Supplier::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'year' => now()->year,
            'quarter' => now()->quarter,
            'otif_percentage' => fake()->randomFloat(2, 85, 100),
            'defect_ppm' => fake()->numberBetween(50, 500),
            'cost_savings_amount' => fake()->randomFloat(2, 1000, 20000),
            'compliance_score' => fake()->randomFloat(2, 80, 100),
            'risk_level' => 'low',
            'is_avl_active' => true,
            'scorecard_data' => [
                'total_orders' => fake()->numberBetween(10, 50),
                'perfect_orders' => fake()->numberBetween(8, 48),
            ],
        ];
    }

    public function highRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_level' => 'high',
            'is_avl_active' => false,
            'otif_percentage' => 60.0,
            'defect_ppm' => 2500,
        ]);
    }
}
