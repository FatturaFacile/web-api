<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RevenueScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'performance_obligation_id' => $this->performance_obligation_id,
            'period_date' => $this->period_date?->toDateString(),
            'scheduled_amount' => (float) $this->scheduled_amount,
            'recognized_amount' => (float) $this->recognized_amount,
            'is_recognized' => (bool) $this->is_recognized,
            'recognized_at' => $this->recognized_at?->toIso8601String(),
        ];
    }
}
