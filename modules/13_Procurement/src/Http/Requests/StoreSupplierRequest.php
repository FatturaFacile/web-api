<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'supplier_code' => ['required', 'string', 'max:50'],
            'company_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'fiscal_code' => ['nullable', 'string', 'max:50'],
            'supplier_category_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms_code' => ['nullable', 'string', 'max:50'],
            'incoterms' => ['nullable', 'string', 'max:10'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'rating' => ['nullable', 'string', 'in:A,B,C,D'],
            'is_approved_vendor' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
