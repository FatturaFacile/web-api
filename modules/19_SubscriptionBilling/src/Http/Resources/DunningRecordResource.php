<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\DunningRecord;

/**
 * A dunning case of an overdue subscription invoice.
 *
 * @mixin DunningRecord
 */
final class DunningRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'invoice_id' => $this->invoice_id,
            'dunning_level' => (int) $this->dunning_level,
            'status' => $this->status,
            'action_taken' => $this->action_taken,
            'retry_count' => (int) $this->retry_count,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'last_failure_reason' => $this->last_failure_reason,
            'days_overdue' => (int) $this->days_overdue,
            'communications_log' => $this->communications_log ?? [],
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
