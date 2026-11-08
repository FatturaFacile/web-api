<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Services\DeliveryFulfillmentService;

/**
 * Delivery note (DDT) of an order or a quote: the lines to ship (order line id => quantity, every open quantity when
 * omitted), the warehouse the goods leave from and the transport data printed on the note.
 */
class ConvertToDeliveryNoteRequest extends FormRequest
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
            'lines' => ['nullable', 'array'],
            'lines.*' => ['numeric', 'gt:0', 'decimal:0,4'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'transport_reason' => ['nullable', 'string', 'max:100'],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:1'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_license_plate' => ['nullable', 'string', 'max:30'],
            'departure_time' => ['nullable', 'date'],
            'shipping_terms' => ['nullable', 'string', 'max:100'],
            'transport_by' => ['nullable', Rule::in(DeliveryFulfillmentService::TransportBy)],
            'courier' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'show_prices' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
