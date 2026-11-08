<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\LeaveAccrualPosting;
use Modules\HumanResources\Models\LeaveBalance;

/**
 * @extends Factory<LeaveAccrualPosting>
 */
final class LeaveAccrualPostingFactory extends Factory
{
    protected $model = LeaveAccrualPosting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'leave_balance_id' => LeaveBalance::factory(),
            'period' => '2026-'.str_pad((string) fake()->numberBetween(1, 12), 2, '0', STR_PAD_LEFT),
            'hours' => '10.00',
        ];
    }
}
