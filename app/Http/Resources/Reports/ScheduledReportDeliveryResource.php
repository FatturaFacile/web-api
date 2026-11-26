<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Models\ScheduledReportDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledReportDelivery
 */
final class ScheduledReportDeliveryResource extends JsonResource
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
            'recipient_email' => $this->recipient_email,
            'frequency' => $this->frequency,
            'period_key' => $this->period_key,
            'language' => $this->language,
            'is_test' => $this->is_test,
            'status' => $this->status,
            'message_id' => $this->message_id,
            'provider_status' => $this->provider_status,
            'error_message' => $this->error_message,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'clicked_at' => $this->clicked_at?->toIso8601String(),
            'open_count' => $this->open_count,
            'click_count' => $this->click_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
