<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\AdvanceReplacement;

final class AdvanceReplacementStoreRequest extends FormRequest
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
            'customer_return_id' => ['nullable', 'uuid'],
            'rma_id' => ['nullable', 'uuid'],
            'contact_id' => ['required', 'uuid'],
            'original_invoice_id' => ['nullable', 'uuid'],
            'faulty_product_id' => ['required', 'uuid'],
            'faulty_serial_number' => ['nullable', 'string', 'max:100'],
            'replacement_product_id' => ['nullable', 'uuid'],
            'replacement_serial_number' => ['nullable', 'string', 'max:100'],
            'replacement_unit_type' => ['nullable', Rule::in(AdvanceReplacement::UnitTypes)],
            'refurbished_item_id' => ['nullable', 'uuid'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'warehouse_id' => ['nullable', 'uuid'],
            'shipped_on' => ['nullable', 'date_format:Y-m-d'],
            'sla_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'penalty_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
