<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Returns\Models\RefurbishedItem;

final class RefurbishedWorkOrderRequest extends FormRequest
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
            'work_order_status' => ['nullable', Rule::in(RefurbishedItem::WorkOrderStatuses)],
            'work_order_steps' => ['nullable', 'array'],
            'refurbishment_cost' => ['nullable', 'numeric', 'min:0'],
            'suggested_sale_price' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
