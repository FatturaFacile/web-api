<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class AddLotComponentRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'component_lot_id' => ['required', 'uuid', $this->tenantExists('stock_lots')],
            'quantity_consumed' => ['nullable', 'numeric', 'gt:0'],
            'production_order_id' => ['nullable', 'uuid', $this->tenantExists('mfg_production_orders')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
