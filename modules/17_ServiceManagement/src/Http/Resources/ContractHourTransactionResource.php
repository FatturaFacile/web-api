<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\ContractHourTransaction;

/**
 * @mixin ContractHourTransaction
 */
final class ContractHourTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'hours' => (string) $this->hours,
            'balance_after' => (string) $this->balance_after,
            'work_order_id' => $this->work_order_id,
            'ticket_id' => $this->ticket_id,
            'equipment_passport_id' => $this->equipment_passport_id,
            'serial_number' => $this->relationLoaded('asset') ? $this->asset?->serial_number : null,
            'technician_name' => $this->technician_name,
            'reference' => $this->reference,
            'description' => $this->description,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
