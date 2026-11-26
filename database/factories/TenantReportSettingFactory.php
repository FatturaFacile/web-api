<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantReportSetting>
 */
class TenantReportSettingFactory extends Factory
{
    protected $model = TenantReportSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'timezone' => 'Europe/Zurich',
            'default_frequency' => NotificationPreference::FREQUENCY_DAILY,
            'default_send_time' => '07:00',
            'sender_name' => 'FatturaFacile ERP',
            'sender_email' => 'reports@fatturafacile.example',
            'logo_url' => null,
            'primary_color' => '#1890ff',
            'secondary_color' => '#001529',
            'custom_header_html' => null,
            'custom_footer_html' => null,
            'enabled_modules' => NotificationPreference::MODULES,
            'rate_limit_per_hour' => 1000,
        ];
    }
}
