<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\HelpdeskSetting;

/**
 * @mixin HelpdeskSetting
 */
final class HelpdeskSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reopen_window_days' => (int) $this->reopen_window_days,
            'business_hours_calendar_id' => $this->business_hours_calendar_id,
            'sla_warning_percent' => (int) $this->sla_warning_percent,
            'csat_enabled' => (bool) $this->csat_enabled,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
