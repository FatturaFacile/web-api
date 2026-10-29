<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PurchaseRequisitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requisition_number' => $this->requisition_number,
            'title' => $this->title,
            'status' => $this->status,
            'urgency' => $this->urgency,
            'required_by_date' => $this->required_by_date?->toDateString(),
            'total_estimated_amount' => (float) $this->total_estimated_amount,
            'currency' => $this->currency,
            'project_id' => $this->project_id,
            'cost_center_id' => $this->cost_center_id,
            'requestor_user_id' => $this->requestor_user_id,
            'is_locked' => (bool) $this->is_locked,
            'lines' => $this->whenLoaded('lines'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
