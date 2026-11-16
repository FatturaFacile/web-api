<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * @extends Factory<BudgetDriver>
 */
final class BudgetDriverFactory extends Factory
{
    protected $model = BudgetDriver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'version_id' => BudgetVersion::factory(),
            'name' => 'Headcount',
            'account_id' => (string) Str::uuid7(),
            'quantity' => '10.000',
            'rate' => '2500.0000',
            'spread_method' => BudgetDriver::Even,
            'start_period' => 1,
            'end_period' => 12,
            'is_active' => true,
        ];
    }
}
