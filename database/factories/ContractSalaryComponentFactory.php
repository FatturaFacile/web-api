<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\EmploymentContract;

/**
 * @extends Factory<ContractSalaryComponent>
 */
final class ContractSalaryComponentFactory extends Factory
{
    protected $model = ContractSalaryComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'contract_id' => EmploymentContract::factory(),
            'wage_type_code' => strtoupper(fake()->bothify('C###')),
            'description' => fake()->words(2, true),
            'amount' => '150.00',
            'frequency' => ContractSalaryComponent::FrequencyMonthly,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
    }
}
