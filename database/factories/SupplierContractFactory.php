<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;

/**
 * @extends Factory<SupplierContract>
 */
class SupplierContractFactory extends Factory
{
    protected $model = SupplierContract::class;

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

        $user = User::first();
        $supplier = Supplier::first() ?? Supplier::factory()->create(['tenant_id' => $tenantId]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'contract_number' => 'CTR-'.fake()->unique()->numerify('####'),
            'title' => fake()->catchPhrase().' Agreement',
            'contract_type' => fake()->randomElement(['framework', 'standard', 'blanket', 'service_level']),
            'total_committed_amount' => fake()->randomFloat(2, 20000, 200000),
            'consumed_amount' => fake()->randomFloat(2, 0, 10000),
            'starts_at' => now()->startOfYear()->toDateString(),
            'ends_at' => now()->addYear()->endOfYear()->toDateString(),
            'payment_terms_code' => 'NET30',
            'incoterms' => 'DAP',
            'status' => 'active',
            'is_locked' => false,
            'created_by' => $user?->id,
        ];
    }

    public function framework(): static
    {
        return $this->state(fn (array $attributes) => [
            'contract_type' => 'framework',
            'status' => 'active',
            'total_committed_amount' => 500000.00,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'starts_at' => now()->subYears(2)->toDateString(),
            'ends_at' => now()->subMonth()->toDateString(),
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'is_locked' => true,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'consumed_amount' => 0.0,
        ]);
    }
}
