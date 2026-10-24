<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
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
        $ignore = $this->route('id');

        return [
            'name' => [$presence, 'string', 'max:100', Rule::unique('price_lists', 'name')->where('tenant_id', $tenantId)->ignore($ignore)],
            'currency' => [$presence, 'string', 'size:3'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.min_quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
