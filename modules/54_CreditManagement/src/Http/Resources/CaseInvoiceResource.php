<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CreditManagement\Models\CreditCaseInvoice;

/**
 * An invoice under collection in a case.
 *
 * @mixin CreditCaseInvoice
 */
final class CaseInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_number' => $this->invoice_number,
            'due_on' => $this->due_on?->toDateString(),
            'outstanding_amount' => $this->outstanding_amount,
        ];
    }
}
