<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AllocateStockReservationRequest extends FormRequest
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
            'source_type' => ['required', 'string', 'max:100'],
            'source_id' => ['required', 'uuid'],
            'warehouse_bin_id' => ['nullable', 'uuid'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
