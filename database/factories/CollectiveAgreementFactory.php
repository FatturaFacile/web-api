<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\CollectiveAgreement;

/**
 * @extends Factory<CollectiveAgreement>
 */
class CollectiveAgreementFactory extends Factory
{
    protected $model = CollectiveAgreement::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('C###')),
            'name' => fake()->words(3, true),
            'installments' => 13,
        ];
    }

    /**
     * Shared by every company and read-only for them.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
