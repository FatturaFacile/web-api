<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\ServiceContract;

/**
 * @mixin ServiceContract
 */
final class ServiceContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'contract_number' => $this->contract_number,
            'name' => $this->name,
            'customer_id' => $this->customer_id,
            'contract_type' => $this->contract_type,
            'status' => $this->status,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'contract_value' => (string) $this->resource->getAttribute('annual_value'),
            'currency' => $this->currency,
            'is_locked' => (bool) $this->is_locked,
            'billing_recurrence' => $this->billing_recurrence,
            'sla_response_hours' => $this->sla_response_hours !== null ? (string) $this->sla_response_hours : null,
            'included_hours' => (string) $this->included_hours,
            'consumed_hours' => (string) $this->consumed_hours,
            'remaining_hours' => $this->remainingHours(),
            'low_balance_threshold_percent' => (int) $this->low_balance_threshold_percent,
            'low_balance' => $this->isLowBalance(),
            'report_email' => $this->report_email,
            'last_residual_report_at' => $this->last_residual_report_at?->toIso8601String(),
            'assets' => $this->whenLoaded('assets', fn () => EquipmentPassportResource::collection($this->assets)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
