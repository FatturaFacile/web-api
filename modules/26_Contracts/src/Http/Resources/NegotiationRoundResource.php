<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractNegotiationRound;

/**
 * A negotiation round of a contract.
 *
 * @mixin ContractNegotiationRound
 */
final class NegotiationRoundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_kind' => $this->contract_kind,
            'contract_id' => $this->contract_id,
            'round_no' => $this->round_no,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'summary' => $this->summary,
            'opened_by' => $this->opened_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
