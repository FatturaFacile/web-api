<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\CguLedgerEntry;

/**
 * @mixin CguLedgerEntry
 */
final class CguLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorization_id' => $this->authorization_id,
            'reference_type' => $this->reference_type->value,
            'reference_id' => $this->reference_id,
            'movement_direction' => $this->movement_direction->value,
            'duty_amount' => $this->duty_amount,
            'vat_amount' => $this->vat_amount,
            'total_cgu_delta' => $this->total_cgu_delta,
            'running_utilized_balance' => $this->running_utilized_balance,
            'remaining_plafond' => $this->remaining_plafond,
            'recorded_at' => $this->recorded_at->toIso8601String(),
        ];
    }
}
