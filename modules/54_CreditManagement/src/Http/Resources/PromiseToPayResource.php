<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditPromiseToPay;

/**
 * A promise to pay.
 *
 * @mixin CreditPromiseToPay
 */
final class PromiseToPayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_id' => $this->case_id,
            'promised_amount' => $this->promised_amount,
            'promised_on' => $this->promised_on?->toDateString(),
            'status' => $this->status,
            'paid_amount' => $this->paid_amount,
            'resolved_on' => $this->resolved_on?->toDateString(),
            'recorded_by' => $this->recorded_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
