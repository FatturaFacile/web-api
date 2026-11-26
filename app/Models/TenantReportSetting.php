<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantReportSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tenant-level branding, timezone, sender, and default configuration for scheduled ERP reports.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $timezone
 * @property string $default_frequency
 * @property string $default_send_time
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property string|null $logo_url
 * @property string $primary_color
 * @property string $secondary_color
 * @property string|null $custom_header_html
 * @property string|null $custom_footer_html
 * @property list<string>|null $enabled_modules
 * @property int $rate_limit_per_hour
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantReportSetting extends Model
{
    /** @use HasFactory<TenantReportSettingFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'tenant_report_settings';

    protected $attributes = [
        'timezone' => 'Europe/Zurich',
        'default_frequency' => NotificationPreference::FREQUENCY_DAILY,
        'default_send_time' => '07:00',
        'primary_color' => '#1890ff',
        'secondary_color' => '#001529',
        'rate_limit_per_hour' => 1000,
    ];

    protected $fillable = [
        'tenant_id',
        'timezone',
        'default_frequency',
        'default_send_time',
        'sender_name',
        'sender_email',
        'logo_url',
        'primary_color',
        'secondary_color',
        'custom_header_html',
        'custom_footer_html',
        'enabled_modules',
        'rate_limit_per_hour',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modules' => 'array',
            'rate_limit_per_hour' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function effectiveEnabledModules(): array
    {
        $modules = $this->enabled_modules;

        if (! is_array($modules) || $modules === []) {
            return NotificationPreference::MODULES;
        }

        return array_values(array_intersect(NotificationPreference::MODULES, array_map('strval', $modules)));
    }

    public function isModuleEnabled(string $module): bool
    {
        return in_array($module, $this->effectiveEnabledModules(), true);
    }

    public static function defaultTimezoneForTenant(?Tenant $tenant): string
    {
        return ($tenant !== null ? $tenant->country : 'CH') === 'IT' ? 'Europe/Rome' : 'Europe/Zurich';
    }
}
