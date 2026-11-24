<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class ProcessGoodsReceiptRequest extends FormRequest
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
            'supplier_delivery_note_ref' => ['required', 'string', 'max:100'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
            'inspection_required' => ['sometimes', 'boolean'],
            'receipt_number' => ['prohibited'],
            'inspection_status' => ['nullable', 'string', 'in:passed,failed,conditional'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'],
            'lines.*.quantity_received' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.quantity_accepted' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.quantity_rejected' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['string', 'max:100', 'distinct'],
            'lines.*.warehouse_bin_id' => ['nullable', 'uuid'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.warehouse_location_id' => ['nullable', 'uuid'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
