<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateDeliveryNoteRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'uuid'],
            'ddt_number' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date'],
            'transport_reason' => ['nullable', 'string', 'max:100'],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:1'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_license_plate' => ['nullable', 'string', 'max:30'],
            'shipping_address' => ['nullable', 'string'],
            'courier' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
