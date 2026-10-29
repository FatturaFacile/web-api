<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;

/**
 * @extends Factory<SupplierEvaluation>
 */
class SupplierEvaluationFactory extends Factory
{
    protected $model = SupplierEvaluation::class;

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
            'evaluation_period_start' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'evaluation_period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'on_time_delivery_rate' => fake()->randomFloat(2, 85, 100),
            'quality_acceptance_rate' => fake()->randomFloat(2, 90, 100),
            'price_competitiveness_score' => fake()->randomFloat(2, 75, 95),
            'overall_rating' => 'A',
            'is_approved_vendor' => true,
            'evaluator_user_id' => $user?->id,
            'comments' => fake()->sentence(),
        ];
    }

    public function excellent(): static
    {
        return $this->state(fn (array $attributes) => [
            'on_time_delivery_rate' => 99.5,
            'quality_acceptance_rate' => 100.0,
            'price_competitiveness_score' => 95.0,
            'overall_rating' => 'A',
            'is_approved_vendor' => true,
        ]);
    }

    public function atRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'on_time_delivery_rate' => 65.0,
            'quality_acceptance_rate' => 70.0,
            'price_competitiveness_score' => 60.0,
            'overall_rating' => 'D',
            'is_approved_vendor' => false,
            'comments' => 'High defect rate and persistent shipping delays.',
        ]);
    }
}
