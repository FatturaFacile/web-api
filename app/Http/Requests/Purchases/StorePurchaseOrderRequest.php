<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'supplier_id' => [$presence, 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)->whereIn('type', ['supplier', 'both'])],
            'date' => [$presence, 'date'],
            'delivery_due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'currency' => [$presence, 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => [$presence, 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
