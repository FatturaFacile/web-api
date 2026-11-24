<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Purchase order of a dropship sales order, with the delivery address of the final customer.
 */
final class CreateDropshipmentRequest extends FormRequest
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
        return [
            'sales_order_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'expected_delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'delivery_address' => ['required', 'array'],
            'delivery_address.name' => ['nullable', 'string', 'max:190'],
            'delivery_address.street' => ['required', 'string', 'max:190'],
            'delivery_address.house_number' => ['nullable', 'string', 'max:20'],
            'delivery_address.postal_code' => ['required', 'string', 'max:20'],
            'delivery_address.city' => ['required', 'string', 'max:120'],
            'delivery_address.region' => ['nullable', 'string', 'max:60'],
            'delivery_address.country_code' => ['required', 'string', 'size:2'],
            'delivery_address.phone' => ['nullable', 'string', 'max:40'],
            'delivery_address.email' => ['nullable', 'email', 'max:190'],
            'delivery_address.delivery_notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array'],
            'lines.*.sales_order_item_id' => ['required', 'uuid'],
            'lines.*.supplier_catalog_id' => ['nullable', 'uuid'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:20'],
            'lines.*.base_uom' => ['nullable', 'string', 'max:20'],
            'lines.*.uom_conversion_factor' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.discount_cascade' => ['nullable', 'array'],
            'lines.*.discount_cascade.*' => ['numeric', 'min:0', 'max:100'],
        ];
    }
}
