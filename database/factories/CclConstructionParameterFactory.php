<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\CclConstructionParameter;

/**
 * @extends Factory<CclConstructionParameter>
 */
final class CclConstructionParameterFactory extends Factory
{
    protected $model = CclConstructionParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'agreement_code' => 'CNM-'.fake()->unique()->numerify('####'),
            'valid_from' => '2027-01-01',
            'annual_hours' => '2112.00',
            'accounting_year_start_month' => 1,
            'minimum_wages' => [
                'Q' => ['TI' => ['monthly' => '6250.00', 'hourly' => '35.50'], 'ZH' => ['monthly' => '6550.00', 'hourly' => '37.20']],
                'A' => ['TI' => ['monthly' => '5850.00', 'hourly' => '33.25'], 'ZH' => ['monthly' => '6150.00', 'hourly' => '34.95']],
                'C' => ['TI' => ['monthly' => '4950.00', 'hourly' => '28.15'], 'ZH' => ['monthly' => '5200.00', 'hourly' => '29.55']],
            ],
            'source' => 'Contratto nazionale mantello per l\'edilizia principale in Svizzera (CNM), allegato salari minimi.',
        ];
    }

    /**
     * Shared by every company.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
