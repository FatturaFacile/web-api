<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\OvertimeRule;

/**
 * Test rules: the threshold and premium are arbitrary sample values, not a legal rate.
 *
 * @extends Factory<OvertimeRule>
 */
final class OvertimeRuleFactory extends Factory
{
    protected $model = OvertimeRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('OT-###')),
            'name' => 'Overtime',
            'basis' => OvertimeRule::BasisDaily,
            'threshold_minutes' => 480,
            'premium_percent' => '25.00',
            'priority' => 0,
            'is_active' => true,
        ];
    }
}
