<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\ReturnGradingInspection;

final class ReturnGradingStoreRequest extends FormRequest
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
            'customer_return_line_id' => ['nullable', 'uuid'],
            'rma_id' => ['nullable', 'uuid'],
            'rma_line_id' => ['nullable', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'quantity_inspected' => ['required', 'numeric', 'gt:0'],
            'grade' => ['required', Rule::in(ReturnGradingInspection::Grades)],
            'disposition' => ['required', Rule::in(ReturnGradingInspection::Dispositions)],
            'checklist' => ['nullable', 'array'],
            'photo_urls' => ['required', 'array', 'min:1'],
            'photo_urls.*' => ['required', 'string', 'max:500'],
            'original_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'devaluation_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'refurbishment_cost' => ['nullable', 'numeric', 'min:0'],
            'suggested_sale_price' => ['nullable', 'numeric', 'min:0'],
            'target_warehouse_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'inspector_notes' => ['nullable', 'string', 'max:2000'],
            'work_order_status' => ['nullable', 'string', 'max:30'],
            'work_order_steps' => ['nullable', 'array'],
        ];
    }
}
