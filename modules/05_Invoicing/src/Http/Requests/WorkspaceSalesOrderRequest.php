<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates or rewrites a draft sales order of the sales orders workspace (header, ATP-relevant dates and lines).
 */
final class WorkspaceSalesOrderRequest extends FormRequest
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
        $creating = $this->isMethod('POST');

        return [
            'contact_id' => [Rule::requiredIf($creating), 'uuid', $this->tenantOwns('contacts')],
            'order_type' => ['nullable', Rule::in(['standard', 'rush', 'dropship'])],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'quote_id' => ['nullable', 'uuid', $this->tenantOwns('quotes')],
            'date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'requested_delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'shipping_method' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', $this->tenantExists('products')],
            'items.*.warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
            'items.*.requested_date' => ['nullable', 'date'],
        ];
    }
}
