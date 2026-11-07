<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\ExpenseCategory;

/**
 * @extends Factory<ExpenseCategory>
 */
final class AssociationsExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'scope' => ExpenseCategory::FivePerMille,
            'code' => strtoupper(fake()->unique()->bothify('CAT-####')),
            'name' => fake()->randomElement(['Risorse umane', 'Costi di funzionamento', 'Acquisto beni e servizi', 'Erogazioni ad attività istituzionali']),
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * A row of the shared catalogue, visible to every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null]);
    }
}
