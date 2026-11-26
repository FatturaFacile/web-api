<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\ScheduledReportDelivery;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledReportDelivery>
 */
class ScheduledReportDeliveryFactory extends Factory
{
    protected $model = ScheduledReportDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'notification_preference_id' => null,
            'recipient_email' => $this->faker->safeEmail(),
            'frequency' => NotificationPreference::FREQUENCY_DAILY,
            'period_key' => now()->format('Y-m-d'),
            'language' => 'it-CH',
            'is_test' => false,
            'status' => ScheduledReportDelivery::STATUS_QUEUED,
            'queued_at' => now(),
        ];
    }
}
