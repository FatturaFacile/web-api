<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProcessGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'supplier_delivery_note_ref' => ['required', 'string', 'max:100'],
            'received_date' => ['required', 'date'],
            'warehouse_id' => ['nullable', 'uuid'],
            'inspection_status' => ['nullable', 'string', 'in:passed,failed,conditional'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.quantity_accepted' => ['required', 'numeric', 'gte:0'],
            'lines.*.quantity_rejected' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.warehouse_location_id' => ['nullable', 'uuid'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
