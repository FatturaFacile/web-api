<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Invoicing\Services\DeliveryFulfillmentService;

/**
 * Draft delivery note of an order of the active company: the order lines shipped (each within its open quantity) and the
 * transport data of the DDT.
 */
final class CreateDeliveryNoteRequest extends FormRequest
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
            'sales_order_id' => ['required', 'uuid', $this->tenantOwns('sales_orders')],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'date' => ['nullable', 'date'],
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
            'shipping_address' => ['nullable', 'string'],
            'courier' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
