<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\ApprovalMatrixTier;

final class InvoiceApprovalMatrixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'cost_center_id' => $this->rules->whereNotNull('cost_center_id')->first()?->cost_center_id,
            'tiers' => $this->tiers->map(static fn (ApprovalMatrixTier $tier): array => [
                'tier_level' => $tier->tier_level,
                'approver_type' => $tier->approver_type,
                'approver_value' => $tier->approver_value,
                'threshold_min' => $tier->threshold_min,
                'threshold_max' => $tier->threshold_max,
            ])->all(),
        ];
    }
}
