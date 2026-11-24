<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Childcare\Models\FeePlan;

/**
 * @extends Factory<FeePlan>
 */
final class ChildcareFeePlanFactory extends Factory
{
    protected $model = FeePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Tempo pieno 5 giorni', 'Tempo parziale mattino', 'Tre giorni settimanali']),
            'basis' => 'monthly_flat',
            'amount' => fake()->randomElement(['980.00', '1450.00', '1820.00']),
            'meal_amount' => '9.50',
            'tax_rate' => '0.00',
            'currency' => 'CHF',
        ];
    }

    /**
     * Billed for every day the child attended.
     */
    public function perDay(): self
    {
        return $this->state(['name' => 'Tariffa giornaliera', 'basis' => 'per_day', 'amount' => '110.00']);
    }
}
