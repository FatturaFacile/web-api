<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordShopFloorExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'production_order_id' => ['required', 'uuid'],
            'production_order_operation_id' => ['required', 'uuid'],
            'work_center_id' => ['required', 'uuid'],
            'operator_id' => ['nullable', 'uuid'],
            'machine_id' => ['nullable', 'uuid'],
            'good_units' => ['nullable', 'numeric', 'min:0'],
            'scrap_units' => ['nullable', 'numeric', 'min:0'],
            'downtime_reason' => ['nullable', 'string', 'max:500'],
            'labor_logs' => ['nullable', 'array'],
            'machine_logs' => ['nullable', 'array'],
        ];
    }
}
