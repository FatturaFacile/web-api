<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovControlTestRun;

/**
 * @extends Factory<GovControlTestRun>
 */
final class GovControlTestRunFactory extends Factory
{
    protected $model = GovControlTestRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'control_id' => GovControl::factory(),
            'tested_on' => fake()->date(),
            'result' => fake()->randomElement(GovControlTestRun::Results),
            'evidence_document_ids' => [],
        ];
    }
}
