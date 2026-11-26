<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationPreference
 */
final class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'enabled' => $this->enabled,
            'frequency' => $this->frequency,
            'custom_cron' => $this->custom_cron,
            'send_time' => $this->send_time,
            'day_of_week' => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'language' => $this->language,
            'include_dashboard' => $this->include_dashboard,
            'include_billing' => $this->include_billing,
            'include_deadlines' => $this->include_deadlines,
            'include_crm' => $this->include_crm,
            'include_calendar' => $this->include_calendar,
            'include_inventory' => $this->include_inventory,
            'include_support' => $this->include_support,
            'include_news' => $this->include_news,
            'include_insights' => $this->include_insights,
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'last_period_key' => $this->last_period_key,
            'unsubscribed_at' => $this->unsubscribed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
