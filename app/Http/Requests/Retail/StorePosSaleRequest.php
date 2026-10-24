<?php

declare(strict_types=1);

namespace App\Http\Requests\Retail;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosSaleRequest extends FormRequest
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

        return [
            'pos_session_id' => ['required', 'uuid', Rule::exists('pos_sessions', 'id')->where('tenant_id', $tenantId)],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'loyalty_card_id' => ['nullable', 'required_if:payment_method,prepaid', 'uuid', Rule::exists('loyalty_cards', 'id')->where('tenant_id', $tenantId)],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mixed', 'prepaid'])],
            'cash_amount' => ['nullable', 'required_if:payment_method,mixed', 'numeric', 'gt:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required_without:items.*.product_id', 'nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
