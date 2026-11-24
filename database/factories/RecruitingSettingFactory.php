<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HumanResources\Models\RecruitingSetting;

/**
 * @extends Factory<RecruitingSetting>
 */
final class RecruitingSettingFactory extends Factory
{
    protected $model = RecruitingSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'retention_days' => fake()->randomElement([90, RecruitingSetting::DefaultRetentionDays, 365]),
        ];
    }
}
