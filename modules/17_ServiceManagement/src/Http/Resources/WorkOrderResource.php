<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'work_order_number' => $this->work_order_number,
            'title' => $this->title,
            'description' => $this->description,
            'work_type' => $this->work_type,
            'priority' => $this->priority,
            'status' => $this->status,
            'customer_id' => $this->customer_id,
            'service_contract_id' => $this->service_contract_id,
            'asset_id' => $this->asset_id,
            'ticket_id' => $this->ticket_id,
            'project_id' => $this->project_id,
            'service_team_id' => $this->service_team_id,
            'lead_technician_id' => $this->lead_technician_id,
            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'actual_start' => $this->actual_start?->toIso8601String(),
            'actual_end' => $this->actual_end?->toIso8601String(),
            'location_address' => $this->location_address,
            'billing_type' => $this->billing_type,
            'total_labor_hours' => (float) $this->total_labor_hours,
            'total_cost' => (float) $this->total_cost,
            'total_billed_amount' => (float) $this->total_billed_amount,
            'is_invoice_ready' => (bool) $this->is_invoice_ready,
            'is_locked' => (bool) $this->is_locked,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
