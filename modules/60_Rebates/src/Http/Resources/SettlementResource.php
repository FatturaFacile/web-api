<?php

declare(strict_types=1);

namespace Modules\Rebates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rebates\Models\RebateSettlement;

/**
 * The settlement of the accrued rebate of an agreement.
 *
 * @mixin RebateSettlement
 */
final class SettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agreement_id' => $this->agreement_id,
            'agreement' => $this->whenLoaded('agreement', fn (): array => ['id' => $this->agreement->id, 'code' => $this->agreement->code, 'name' => $this->agreement->name, 'direction' => $this->agreement->direction]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'settlement_type' => $this->settlement_type,
            'credit_note_id' => $this->credit_note_id,
            'settled_on' => $this->settled_on->toDateString(),
            'notes' => $this->notes,
            'accruals' => AccrualResource::collection($this->whenLoaded('accruals')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
