<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportSchedule;

/**
 * @extends Factory<ExportSchedule>
 */
final class ExportScheduleFactory extends Factory
{
    protected $model = ExportSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'designed_report_id' => DesignedReport::factory(),
            'frequency' => 'daily',
            'day_of_week' => null,
            'day_of_month' => null,
            'format' => 'csv',
            'delivery' => 'email',
            'recipients' => [fake()->unique()->safeEmail()],
            'is_active' => true,
            'next_run_on' => now()->toDateString(),
        ];
    }
}
