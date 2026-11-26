<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'channel' => NotificationPreference::CHANNEL_ERP_REPORT,
            'enabled' => true,
            'frequency' => NotificationPreference::FREQUENCY_DAILY,
            'custom_cron' => null,
            'send_time' => '07:00',
            'day_of_week' => null,
            'day_of_month' => null,
            'language' => 'it-CH',
            'include_dashboard' => true,
            'include_billing' => true,
            'include_deadlines' => true,
            'include_crm' => true,
            'include_calendar' => true,
            'include_inventory' => true,
            'include_support' => true,
            'include_news' => true,
            'include_insights' => true,
            'last_sent_at' => null,
            'last_period_key' => null,
            'unsubscribed_at' => null,
        ];
    }
}
