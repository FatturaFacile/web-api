<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditDispute;

/**
 * A dispute raised by a customer.
 *
 * @mixin CreditDispute
 */
final class DisputeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'case_id' => $this->case_id,
            'invoice_id' => $this->invoice_id,
            'disputed_amount' => $this->disputed_amount,
            'reason_code' => $this->reason_code,
            'status' => $this->status,
            'opened_on' => $this->opened_on->toDateString(),
            'resolved_on' => $this->resolved_on?->toDateString(),
            'credited_amount' => $this->credited_amount,
            'description' => $this->description,
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'customer_name' => $this->customer_name ?? null,
        ];
    }
}
