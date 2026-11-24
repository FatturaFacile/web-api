<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ItalianVat\Models\F24Payment;

/**
 * @mixin F24Payment
 */
final class F24PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'tax_code' => $this->tax_code,
            'amount_due' => (string) $this->amount_due,
            'amount_paid' => (string) $this->amount_paid,
            'due_date' => $this->due_date->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'f24_reference' => $this->f24_reference,
            'withholding_settlement_id' => $this->withholding_settlement_id,
            'status' => $this->status,
        ];
    }
}
