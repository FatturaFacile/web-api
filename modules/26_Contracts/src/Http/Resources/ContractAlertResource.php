<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of the contract alert.
 */
final class ContractAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'contract_kind' => $this->contract_kind,
            'contract_id' => (string) $this->contract_id,
            'alert_type' => $this->alert_type,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
        ];
    }
}
