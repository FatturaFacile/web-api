<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

/**
 * A shop floor job card with its downtime and the confirmation it produced.
 *
 * @mixin MfgShopFloorExecution
 */
final class MfgShopFloorExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'execution_number' => $this->execution_number,
            'production_order_id' => $this->production_order_id,
            'production_order_operation_id' => $this->production_order_operation_id,
            'work_center_id' => $this->work_center_id,
            'operator_id' => $this->operator_id,
            'machine_id' => $this->machine_id,
            'status' => $this->status,
            'good_units' => $this->good_units,
            'scrap_units' => $this->scrap_units,
            'downtime_minutes' => $this->downtime_minutes,
            'downtime_reason' => $this->downtime_reason,
            'confirmation_id' => $this->confirmation_id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
