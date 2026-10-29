<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * @extends Factory<PurchaseRequisition>
 */
class PurchaseRequisitionFactory extends Factory
{
    protected $model = PurchaseRequisition::class;

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

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'requisition_number' => 'REQ-'.fake()->unique()->numerify('####'),
            'title' => fake()->words(4, true).' Requisition',
            'requestor_user_id' => $user?->id,
            'urgency' => fake()->randomElement(['low', 'medium', 'high', 'emergency']),
            'required_by_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'total_estimated_amount' => fake()->randomFloat(2, 500, 25000),
            'currency' => 'EUR',
            'notes' => fake()->sentence(),
            'is_locked' => false,
            'created_by' => $user?->id,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'is_locked' => false,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'is_locked' => true,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'is_locked' => false,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'is_locked' => false,
        ]);
    }

    public function convertedToPo(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'converted_to_po',
            'is_locked' => false,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'urgency' => 'emergency',
            'required_by_date' => now()->addDays(2)->toDateString(),
        ]);
    }
}
