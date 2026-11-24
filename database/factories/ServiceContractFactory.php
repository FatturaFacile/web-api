<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Models\ServiceContract;

class ServiceContractFactory extends Factory
{
    protected $model = ServiceContract::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_number' => 'SC-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'title' => fake()->catchPhrase().' Agreement',
            'customer_id' => (string) Str::uuid7(),
            'status' => 'active',
            'start_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->addMonths(10)->toDateString(),
            'annual_value' => fake()->randomFloat(2, 5000, 60000),
            'currency' => 'EUR',
            'auto_renew' => true,
            'is_locked' => false,
            'terms' => [
                'contract_type' => fake()->randomElement(['sla', 'maintenance', 'support', 'warranty', 'subscription']),
                'billing_cycle' => 'monthly',
            ],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'end_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
