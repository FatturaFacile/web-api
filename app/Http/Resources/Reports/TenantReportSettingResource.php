<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Models\TenantReportSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantReportSetting
 */
final class TenantReportSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'timezone' => $this->timezone,
            'default_frequency' => $this->default_frequency,
            'default_send_time' => $this->default_send_time,
            'sender_name' => $this->sender_name,
            'sender_email' => $this->sender_email,
            'logo_url' => $this->logo_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'custom_header_html' => $this->custom_header_html,
            'custom_footer_html' => $this->custom_footer_html,
            'enabled_modules' => $this->effectiveEnabledModules(),
            'rate_limit_per_hour' => $this->rate_limit_per_hour,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
