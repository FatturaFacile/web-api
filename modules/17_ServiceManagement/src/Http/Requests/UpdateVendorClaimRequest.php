<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class UpdateVendorClaimRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_rma_reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'claimed_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
