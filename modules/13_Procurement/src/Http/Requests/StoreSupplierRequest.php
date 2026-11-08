<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSupplierRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_code' => ['required', 'string', 'max:50', Rule::unique('suppliers', 'supplier_code')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'company_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'fiscal_code' => ['nullable', 'string', 'max:50'],
            'supplier_category_id' => ['nullable', 'uuid', $this->tenantExists('supplier_categories')],
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
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
