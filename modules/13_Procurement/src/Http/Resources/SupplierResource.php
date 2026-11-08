<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\Supplier;

/**
 * A supplier with its qualification status and tolerances.
 *
 * @mixin Supplier
 */
final class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_code' => $this->supplier_code,
            'company_name' => $this->company_name,
            'vat_number' => $this->vat_number,
            'fiscal_code' => $this->fiscal_code,
            'status' => $this->status,
            'is_approved_vendor' => (bool) $this->is_approved_vendor,
            'rating' => $this->rating,
            'credit_limit' => (float) $this->credit_limit,
            'currency' => $this->currency,
            'payment_terms_code' => $this->payment_terms_code,
            'incoterms' => $this->incoterms,
            'is_locked' => (bool) $this->is_locked,
            'contact_id' => $this->contact_id,
            'qualification_status' => $this->qualification_status,
            'qualified_at' => $this->qualified_at?->toDateString(),
            'qualification_review_date' => $this->qualification_review_date?->toDateString(),
            'over_receipt_tolerance_percent' => $this->over_receipt_tolerance_percent !== null ? (string) $this->over_receipt_tolerance_percent : null,
            'price_tolerance_percent' => $this->price_tolerance_percent !== null ? (string) $this->price_tolerance_percent : null,
            'category' => new SupplierCategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
