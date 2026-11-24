<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * A business trip with, when loaded, the employee, the itinerary, the per-diem days and the mileage claims.
 *
 * @mixin TravelTrip
 */
final class TripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'user_id' => $this->user_id,
            'employee' => $this->whenLoaded('employee', fn (): ?array => $this->employee === null ? null : ['id' => $this->employee->id, 'name' => $this->employee->name]),
            'purpose' => $this->purpose,
            'destination_city' => $this->destination_city,
            'destination_country' => $this->destination_country,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'departure_time' => $this->departure_time,
            'return_time' => $this->return_time,
            'currency' => $this->currency,
            'rate_jurisdiction' => $this->rate_jurisdiction,
            'estimated_budget' => $this->estimated_budget,
            'advance_amount' => $this->advance_amount,
            'advance_paid_at' => $this->advance_paid_at?->toDateString(),
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'reconciled_by' => $this->reconciled_by,
            'reconciled_expenses_total' => $this->reconciled_expenses_total,
            'reconciled_per_diem_total' => $this->reconciled_per_diem_total,
            'reconciled_mileage_total' => $this->reconciled_mileage_total,
            'reconciled_advance_total' => $this->reconciled_advance_total,
            'reconciled_balance' => $this->reconciled_balance,
            'reconciled_direction' => $this->reconciled_direction,
            'legs' => LegResource::collection($this->whenLoaded('legs')),
            'per_diem_days' => PerDiemDayResource::collection($this->whenLoaded('perDiemDays')),
            'mileage_claims' => MileageClaimResource::collection($this->whenLoaded('mileageClaims')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
