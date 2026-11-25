<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GdoTradeTerms\Models\GdoContract;

/**
 * A GDO framework contract; the detail adds the rebate scales and the promotions when they are loaded.
 *
 * @mixin GdoContract
 */
final class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contact = $this->relationLoaded('contact') ? $this->contact : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_id' => $this->contact_id,
            'contact' => $contact === null ? null : ['id' => $contact->id, 'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')), 'country' => $contact->country],
            'include_child_contacts' => $this->include_child_contacts,
            'jurisdiction' => $this->jurisdiction->value,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'status' => $this->status->value,
            'discount_1_percent' => $this->discount_1_percent,
            'discount_2_percent' => $this->discount_2_percent,
            'discount_3_percent' => $this->discount_3_percent,
            'cedi_discount_percent' => $this->cedi_discount_percent,
            'pallet_discount_amount' => $this->pallet_discount_amount,
            'effective_on_invoice_discount_percent' => $this->effectiveOnInvoiceDiscountPercent(),
            'tolerance_percent' => $this->tolerance_percent,
            'expense_account_id' => $this->expense_account_id,
            'provision_account_id' => $this->provision_account_id,
            'payable_account_id' => $this->payable_account_id,
            'vat_account_id' => $this->vat_account_id,
            'notes' => $this->notes,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'scales' => ScaleResource::collection($this->whenLoaded('scales')),
            'promotions' => PromotionResource::collection($this->whenLoaded('promotions')),
        ];
    }
}
