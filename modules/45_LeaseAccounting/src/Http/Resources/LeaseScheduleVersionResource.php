<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaseAccounting\Models\LeaseScheduleVersion;

/**
 * A measurement of a lease: the initial one or a remeasurement, with its effect on the liability and the asset.
 *
 * @mixin LeaseScheduleVersion
 */
final class LeaseScheduleVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lease_contract_id' => $this->lease_contract_id,
            'version_number' => $this->version_number,
            'reason' => $this->reason,
            'effective_date' => $this->effective_date->toDateString(),
            'start_period_number' => $this->start_period_number,
            'end_period_number' => $this->end_period_number,
            'discount_rate_percent' => $this->discount_rate_percent,
            'payment_amount' => $this->payment_amount,
            'liability_before' => $this->liability_before,
            'liability_after' => $this->liability_after,
            'rou_before' => $this->rou_before,
            'rou_after' => $this->rou_after,
            'liability_adjustment' => $this->liability_adjustment,
            'rou_adjustment' => $this->rou_adjustment,
            'result_amount' => $this->result_amount,
            'total_undiscounted' => $this->total_undiscounted,
            'journal_entry_id' => $this->journal_entry_id,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
