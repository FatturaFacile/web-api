<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScheduledReportAuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledReportAuditLog>
 */
class ScheduledReportAuditLogFactory extends Factory
{
    protected $model = ScheduledReportAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'delivery_id' => null,
            'event_type' => ScheduledReportAuditLog::EVENT_REPORT_QUEUED,
            'recipient_email' => $this->faker->safeEmail(),
            'period_key' => now()->format('Y-m-d'),
            'occurred_at' => now(),
        ];
    }
}
