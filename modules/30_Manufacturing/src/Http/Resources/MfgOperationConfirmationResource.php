<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\MfgOperationConfirmation;

/**
 * An operation confirmation with times, quantities and the costs absorbed into WIP (decimal strings).
 *
 * @mixin MfgOperationConfirmation
 */
final class MfgOperationConfirmationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'confirmation_number' => $this->confirmation_number,
            'production_order_id' => $this->production_order_id,
            'production_order_operation_id' => $this->production_order_operation_id,
            'shop_floor_execution_id' => $this->shop_floor_execution_id,
            'subcontract_order_id' => $this->subcontract_order_id,
            'setup_minutes' => $this->setup_minutes,
            'run_minutes' => $this->run_minutes,
            'labor_minutes' => $this->labor_minutes,
            'downtime_minutes' => $this->downtime_minutes,
            'good_quantity' => $this->good_quantity,
            'scrap_quantity' => $this->scrap_quantity,
            'scrap_reason' => $this->scrap_reason,
            'is_final' => $this->is_final,
            'labor_cost' => $this->labor_cost,
            'overhead_cost' => $this->overhead_cost,
            'subcontract_cost' => $this->subcontract_cost,
            'posting_date' => $this->posting_date->toDateString(),
            'journal_entry_id' => $this->journal_entry_id,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at->toIso8601String(),
        ];
    }
}
