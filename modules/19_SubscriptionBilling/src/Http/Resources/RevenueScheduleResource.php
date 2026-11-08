<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SubscriptionBilling\Models\RevenueSchedule;

/**
 * A monthly revenue slice of a performance obligation.
 *
 * @mixin RevenueSchedule
 */
final class RevenueScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'performance_obligation_id' => $this->performance_obligation_id,
            'period_date' => $this->period_date?->toDateString(),
            'scheduled_amount' => (string) $this->getRawOriginal('scheduled_amount'),
            'recognized_amount' => (string) $this->getRawOriginal('recognized_amount'),
            'is_recognized' => (bool) $this->is_recognized,
            'recognized_at' => $this->recognized_at?->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
        ];
    }
}
