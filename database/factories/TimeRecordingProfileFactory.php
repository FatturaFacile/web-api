<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;

/**
 * @extends Factory<TimeRecordingProfile>
 */
class TimeRecordingProfileFactory extends Factory
{
    protected $model = TimeRecordingProfile::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'mode' => TimeRecordingMode::Detailed,
            'legal_max_weekly_hours' => 45,
            'weekly_hours' => 42,
            'work_days_per_week' => 5,
            'hire_date' => '2020-01-01',
            'canton' => 'TI',
        ];
    }

    public function simplified(?string $agreementDate = '2026-01-01'): static
    {
        return $this->state(['mode' => TimeRecordingMode::Simplified, 'agreement_date' => $agreementDate]);
    }
}
