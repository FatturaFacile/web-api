<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\TimeDailyRecord;

/**
 * @extends Factory<TimeDailyRecord>
 */
class TimeDailyRecordFactory extends Factory
{
    protected $model = TimeDailyRecord::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'work_date' => '2026-11-02',
            'worked_minutes' => 480,
        ];
    }
}
