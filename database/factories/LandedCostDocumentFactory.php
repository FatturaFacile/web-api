<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Inventory\Models\LandedCostDocument;

/**
 * @extends Factory<LandedCostDocument>
 */
class LandedCostDocumentFactory extends Factory
{
    protected $model = LandedCostDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'number' => 'LC-'.now()->format('Y').'-'.fake()->unique()->numerify('#####'),
            'status' => 'draft',
            'cost_type' => fake()->randomElement(['freight', 'customs_duty', 'insurance', 'brokerage', 'other']),
            'allocation_key' => 'value',
            'currency' => 'EUR',
            'exchange_rate' => 1,
            'total_amount' => fake()->randomFloat(2, 10, 1000),
            'document_date' => now()->toDateString(),
        ];
    }

    public function applied(): static
    {
        return $this->state(fn () => ['status' => 'applied', 'applied_at' => now()]);
    }
}
