<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'category' => new SupplierCategoryResource($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
