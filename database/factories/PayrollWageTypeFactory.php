<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollWageType;

/**
 * @extends Factory<PayrollWageType>
 */
final class PayrollWageTypeFactory extends Factory
{
    protected $model = PayrollWageType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => fake()->unique()->numerify('5###'),
            'name' => fake()->randomElement(['Indennità di turno', 'Premio di produzione', 'Indennità di trasferta']),
            'kind' => 'Earning',
            'subject_to_ahv' => true,
            'subject_to_alv' => true,
            'subject_to_uvg' => true,
            'subject_to_ktg' => true,
            'subject_to_bvg' => false,
            'subject_to_withholding_tax' => true,
            'is_irregular' => false,
            'certificate_box' => '1',
            'sort_order' => fake()->numberBetween(100, 900),
            'is_system' => false,
            'is_active' => true,
        ];
    }
}
