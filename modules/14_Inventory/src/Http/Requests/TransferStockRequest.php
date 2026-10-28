<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferStockRequest extends FormRequest
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
            'stock_item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'movement_type' => ['required', 'string', 'in:INTERNAL_TRANSFER,PURCHASE_RECEIPT,SALES_DELIVERY,ADJUSTMENT_IN,ADJUSTMENT_OUT,SCRAP,PRODUCTION_CONSUMPTION,PRODUCTION_OUTPUT,RETURN_CUSTOMER,RETURN_SUPPLIER'],
            'source_bin_id' => ['nullable', 'uuid'],
            'destination_bin_id' => ['nullable', 'uuid'],
            'stock_lot_id' => ['nullable', 'uuid'],
            'stock_serial_number_id' => ['nullable', 'uuid'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
