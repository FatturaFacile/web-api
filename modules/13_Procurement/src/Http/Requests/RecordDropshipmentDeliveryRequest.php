<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delivery notice of the supplier for a dropshipment: proof, date and the delivered quantity of each line.
 */
final class RecordDropshipmentDeliveryRequest extends FormRequest
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
            'delivered_at' => ['nullable', 'date'],
            'delivery_proof_reference' => ['nullable', 'string', 'max:190'],
            'supplier_shipment_reference' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.quantity_delivered' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
