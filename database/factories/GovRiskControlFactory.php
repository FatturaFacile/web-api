<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Models\GovRiskControl;

/**
 * @extends Factory<GovRiskControl>
 */
final class GovRiskControlFactory extends Factory
{
    protected $model = GovRiskControl::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'risk_id' => GovRisk::factory(),
            'control_id' => GovControl::factory(),
        ];
    }
}
