<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Helpdesk settings of a tenant: reopen window, SLA warning threshold, business hours calendar, CSAT surveys.
 */
final class HelpdeskSetting extends BaseEnterpriseModel
{
    protected $table = 'esm_helpdesk_settings';

    protected function casts(): array
    {
        return [
            'reopen_window_days' => 'integer',
            'sla_warning_percent' => 'integer',
            'csat_enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * The settings of the active tenant, or an unsaved instance with the installation defaults.
     */
    public static function current(?string $tenantId): self
    {
        $settings = $tenantId === null ? null : self::query()->where('tenant_id', $tenantId)->first();

        return $settings ?? new self([
            'tenant_id' => $tenantId,
            'reopen_window_days' => (int) config('helpdesk.reopen_window_days', 7),
            'sla_warning_percent' => (int) config('helpdesk.sla_warning_percent', 80),
            'csat_enabled' => true,
        ]);
    }

    /**
     * @return BelongsTo<BusinessHoursCalendar, $this>
     */
    public function businessHoursCalendar(): BelongsTo
    {
        return $this->belongsTo(BusinessHoursCalendar::class, 'business_hours_calendar_id');
    }
}
