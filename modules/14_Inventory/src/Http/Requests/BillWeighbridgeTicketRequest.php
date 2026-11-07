<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class BillWeighbridgeTicketRequest extends FormRequest
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
            'delivery_note_item_id' => ['required', 'uuid', $this->tenantExists('delivery_note_items')],
            'billing_uom' => ['required', 'string', 'in:kg,t,m3'],
            'density_kg_m3' => ['required_if:billing_uom,m3', 'nullable', 'numeric', 'gt:0'],
        ];
    }
}
