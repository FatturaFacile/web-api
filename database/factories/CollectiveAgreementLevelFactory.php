<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;

/**
 * @extends Factory<CollectiveAgreementLevel>
 */
final class CollectiveAgreementLevelFactory extends Factory
{
    protected $model = CollectiveAgreementLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'agreement_id' => static fn (array $attributes): string => (string) CollectiveAgreement::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'level' => fake()->randomElement(['1', '2', '3', '4', '5', '6', '7', 'Quadro']),
            'description' => 'Impiegato di concetto',
            'monthly_minimum' => fake()->randomElement(['1650.00', '1820.00', '1975.00', '2140.00']),
        ];
    }
}
