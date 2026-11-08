<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Price determination of a product for a customer, a quantity and a date.
 */
class SalesPriceRequest extends FormRequest
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
            'product_id' => ['required', 'uuid', $this->tenantExists('products')],
            'contact_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'date' => ['nullable', 'date'],
            'price_list_id' => ['nullable', 'uuid', $this->tenantOwns('price_lists')],
            'discount_policy_id' => ['nullable', 'uuid', $this->tenantOwns('discount_policies')],
            'order_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
