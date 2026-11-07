<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\PriceResolver;
use App\Services\Tenancy\TenantContext;
use Closure;
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
            'parent_price_list_id' => [
                'nullable',
                'uuid',
                Rule::exists('price_lists', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id')),
                function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
                    if ($ignore !== null && app(PriceResolver::class)->wouldLoop((string) $ignore, (string) $value)) {
                        $fail(__('catalog.validation.price_list_loop'));
                    }
                },
            ],
            'price_adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.valid_from' => ['nullable', 'date'],
            'items.*.valid_to' => ['nullable', 'date', 'after_or_equal:items.*.valid_from'],
        ];
    }
}
