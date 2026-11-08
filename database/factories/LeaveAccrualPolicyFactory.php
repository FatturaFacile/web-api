<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\LeaveAccrualPolicy;
use Modules\HumanResources\Models\LeaveType;

/**
 * Test policies: the entitlement is an arbitrary sample value, not a statutory minimum.
 *
 * @extends Factory<LeaveAccrualPolicy>
 */
final class LeaveAccrualPolicyFactory extends Factory
{
    protected $model = LeaveAccrualPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'leave_type_id' => LeaveType::factory(),
            'code' => strtoupper(fake()->unique()->bothify('ACC-###')),
            'name' => 'Accrual policy',
            'employment_type' => null,
            'annual_entitlement_hours' => '120.00',
            'accrual_frequency' => LeaveAccrualPolicy::FrequencyMonthly,
            'prorate_by_fte' => true,
            'valid_from' => '2026-01-01',
            'is_active' => true,
        ];
    }
}
