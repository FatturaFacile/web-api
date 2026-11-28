<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\OliveMillAndGrainMill\Domain\Services\SianComplianceService;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

/**
 * @mixin SianMovement
 */
final class SianMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $compliance = SianComplianceService::fromConfig();

        return [
            'id' => $this->id,
            'progressive' => $this->progressive,
            'operation_date' => $this->operation_date->format('Y-m-d'),
            'transmission_deadline' => $this->transmission_deadline->format('Y-m-d'),
            'causal' => $this->causal->value,
            'from_tank_id' => $this->from_tank_id,
            'to_tank_id' => $this->to_tank_id,
            'counterparty_contact_id' => $this->counterparty_contact_id,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_cuaa' => $this->counterparty_cuaa,
            'quantity_kg' => $this->quantity_kg,
            'quantity_l' => $this->quantity_l,
            'category' => $this->category?->value,
            'origin_designation' => $this->origin_designation?->value,
            'lot_code' => $this->lot_code,
            'bottle_format' => $this->bottle_format,
            'bottle_count' => $this->bottle_count,
            'package_lot' => $this->package_lot,
            'best_before' => $this->best_before?->format('Y-m-d'),
            'status' => $this->status->value,
            'sian_protocol' => $this->sian_protocol,
            'transmitted_at' => $this->transmitted_at?->toIso8601String(),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'error_message' => $this->error_message,
            'notes' => $this->notes,
            'from_tank_code' => $this->whenLoaded('fromTank', fn (): ?string => $this->fromTank?->code),
            'to_tank_code' => $this->whenLoaded('toTank', fn (): ?string => $this->toTank?->code),
            'days_left' => $this->status->isPending() ? $compliance->daysLeft($this->transmission_deadline) : null,
            'alert_level' => $this->status->isPending() ? $compliance->alertLevel($this->transmission_deadline) : 'none',
        ];
    }
}
