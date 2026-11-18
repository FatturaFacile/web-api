<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use OpenApi\Attributes as OA;

/**
 * @mixin ServiceContract
 */
#[OA\Schema(
    schema: 'FsmMaintenanceContract',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_number', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'suspended', 'expired', 'cancelled']),
        new OA\Property(property: 'coverage_type', type: 'string', enum: ['labor_only', 'parts_and_labor', 'preventive_only', 'full_comprehensive']),
        new OA\Property(property: 'preventive_frequency_rule', type: 'object', nullable: true, example: ['calendar' => ['every' => 6, 'unit' => 'month'], 'meter' => ['operating_hours' => 500, 'cycles' => 10000, 'lead_percent' => 10], 'lead_days' => 14]),
        new OA\Property(property: 'included_visits_per_year', type: 'integer', nullable: true),
        new OA\Property(property: 'used_visits_current_year', type: 'integer'),
        new OA\Property(property: 'callout_fee_amount', type: 'string'),
        new OA\Property(property: 'hourly_labor_rate', type: 'string', nullable: true),
        new OA\Property(property: 'recurring_billing_subscription_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'equipment_passport_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    ],
)]
final class MaintenanceContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'customer_id' => (string) $this->customer_id,
            'contract_number' => (string) $this->contract_number,
            'title' => (string) $this->title,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => (string) $this->status,
            'coverage_type' => (string) $this->coverage_type,
            'preventive_frequency_rule' => $this->preventive_frequency_rule,
            'included_visits_per_year' => $this->included_visits_per_year,
            'used_visits_current_year' => (int) $this->used_visits_current_year,
            'callout_fee_amount' => (string) $this->callout_fee_amount,
            'hourly_labor_rate' => $this->hourly_labor_rate,
            'recurring_billing_subscription_id' => $this->recurring_billing_subscription_id,
            'currency' => (string) $this->currency,
            'notes' => $this->notes,
            'equipment_passport_ids' => SerializedEquipmentPassport::query()->where('service_contract_id', $this->id)->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
        ];
    }
}
