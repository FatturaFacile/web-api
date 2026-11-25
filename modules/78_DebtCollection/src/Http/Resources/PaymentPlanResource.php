<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\PaymentPlan;

/**
 * @mixin PaymentPlan
 */
final class PaymentPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer === null ? null : (trim((string) $this->customer->company_name) !== '' ? (string) $this->customer->company_name : trim($this->customer->first_name.' '.$this->customer->last_name))),
            'currency' => $this->currency,
            'total_amount' => (string) $this->total_amount,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status,
            'receivable_item_ids' => $this->receivable_item_ids ?? [],
            'installments' => $this->installments ?? [],
            'notes' => $this->notes,
        ];
    }
}
