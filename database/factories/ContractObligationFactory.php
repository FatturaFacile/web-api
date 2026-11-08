<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Contracts\Models\ContractObligation;

/**
 * @extends Factory<ContractObligation>
 */
final class ContractObligationFactory extends Factory
{
    protected $model = ContractObligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contract_kind' => 'customer',
            'contract_id' => (string) Str::uuid7(),
            'type' => 'obligation',
            'title' => fake()->sentence(4),
            'responsible_party' => 'own',
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'open',
            'remind_days_before' => 7,
        ];
    }

    /**
     * A milestone with a payment attached.
     */
    public function milestone(string $amount = '1000.00'): static
    {
        return $this->state(['type' => 'milestone', 'amount' => $amount, 'currency' => 'EUR']);
    }
}
