<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HealthExpenses\Models\HealthExpenseSetting;

/**
 * @extends Factory<HealthExpenseSetting>
 */
final class HealthExpenseSettingFactory extends Factory
{
    protected $model = HealthExpenseSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'is_enabled' => true,
            'subject_type' => 'professional',
            'owner_fiscal_code' => 'BNCLRA75M41F205Z',
            'region_code' => null,
            'asl_code' => null,
            'ssa_code' => null,
        ];
    }
}
