<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CorporateConventions\Models\ConventionSplitBilling;

/**
 * @mixin ConventionSplitBilling
 */
final class SplitBillingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'convention_id' => $this->convention_id,
            'convention_name' => $this->whenLoaded('convention', fn (): ?string => $this->convention?->name),
            'enrollment_id' => $this->enrollment_id,
            'beneficiary_name' => $this->whenLoaded('enrollment', fn (): ?string => $this->enrollment?->full_name),
            'source_document_type' => $this->source_document_type->value,
            'source_document_id' => $this->source_document_id,
            'transaction_date' => $this->transaction_date->toDateString(),
            'currency' => $this->currency,
            'total_gross_amount' => $this->total_gross_amount,
            'convention_discount_amount' => $this->convention_discount_amount,
            'employer_subsidy_amount' => $this->employer_subsidy_amount,
            'employee_paid_amount' => $this->employee_paid_amount,
            'employee_document_id' => $this->employee_document_id,
            'b2b_consolidated_invoice_id' => $this->b2b_consolidated_invoice_id,
            'billing_status' => $this->billing_status->value,
            'notes' => $this->notes,
        ];
    }
}
