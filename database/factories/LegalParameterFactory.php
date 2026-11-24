<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Services\LegalParameterService;

/**
 * @extends Factory<LegalParameter>
 */
final class LegalParameterFactory extends Factory
{
    protected $model = LegalParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => LegalParameterService::CashStatementThreshold,
            'year' => fake()->unique()->numberBetween(2000, 2099),
            'value' => '300000.00',
            'source_reference' => 'D.Lgs. 117/2017, art. 13 c.2',
            'notes' => null,
        ];
    }

    /**
     * A parameter shared by every company of the installation.
     */
    public function generic(): static
    {
        return $this->state(['tenant_id' => null])->afterMaking(static fn ($model) => $model->declareGlobal());
    }
}
