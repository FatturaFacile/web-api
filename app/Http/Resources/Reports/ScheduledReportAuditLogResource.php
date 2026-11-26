<?php

declare(strict_types=1);

namespace App\Http\Resources\Reports;

use App\Models\ScheduledReportAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledReportAuditLog
 */
final class ScheduledReportAuditLogResource extends JsonResource
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
            'delivery_id' => $this->delivery_id,
            'event_type' => $this->event_type,
            'recipient_email' => $this->recipient_email,
            'period_key' => $this->period_key,
            'message_id' => $this->message_id,
            'provider_status' => $this->provider_status,
            'ip_address' => $this->ip_address,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
