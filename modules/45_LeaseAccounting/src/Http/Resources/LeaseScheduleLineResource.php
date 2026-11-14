<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;

/**
 * A month of the schedule of a lease: liability roll-forward and right-of-use asset depreciation.
 *
 * @mixin LeaseScheduleLine
 */
final class LeaseScheduleLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_version_id' => $this->schedule_version_id,
            'period_number' => $this->period_number,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'opening_liability' => $this->opening_liability,
            'payment' => $this->payment,
            'interest' => $this->interest,
            'principal' => $this->principal,
            'closing_liability' => $this->closing_liability,
            'rou_opening' => $this->rou_opening,
            'depreciation' => $this->depreciation,
            'rou_closing' => $this->rou_closing,
            'status' => $this->status,
        ];
    }
}
