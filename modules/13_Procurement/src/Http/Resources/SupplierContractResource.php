<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupplierContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'title' => $this->title,
            'contract_type' => $this->contract_type,
            'total_committed_amount' => (float) $this->total_committed_amount,
            'consumed_amount' => (float) $this->consumed_amount,
            'minimum_spend_amount' => $this->minimum_spend_amount === null ? null : (float) $this->minimum_spend_amount,
            'alert_threshold_percent' => (float) ($this->alert_threshold_percent ?? 80),
            'currency' => $this->currency ?? 'EUR',
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'status' => $this->status,
            'is_locked' => (bool) $this->is_locked,
            'supplier_id' => $this->supplier_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
