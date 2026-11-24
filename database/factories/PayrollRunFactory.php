<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollRun;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'year' => 2026,
            'month' => fake()->numberBetween(1, 12),
            'country' => 'CH',
            'currency' => 'CHF',
            'status' => PayrollRun::StatusDraft,
        ];
    }
}
