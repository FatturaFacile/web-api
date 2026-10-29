<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordQualityInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quality_plan_id' => ['required', 'uuid'],
            'production_order_id' => ['nullable', 'uuid'],
            'operation_id' => ['nullable', 'uuid'],
            'inspector_id' => ['nullable', 'uuid'],
            'sample_size' => ['nullable', 'integer', 'min:1'],
            'defect_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'characteristic_results' => ['nullable', 'array'],
        ];
    }
}
