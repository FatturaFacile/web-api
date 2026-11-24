<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\PaymentLine;

/**
 * @mixin PaymentLine
 */
final class PaymentLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_batch_id' => $this->payment_batch_id,
            'line_number' => (int) $this->line_number,
            'recipient_type' => $this->recipient_type,
            'recipient_name' => $this->recipient_name,
            'recipient_iban' => $this->recipient_iban,
            'recipient_bic' => $this->recipient_bic,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'remittance_reference' => $this->remittance_reference,
            'status' => $this->status,
            'execution_date' => $this->execution_date?->toDateString(),
        ];
    }
}
