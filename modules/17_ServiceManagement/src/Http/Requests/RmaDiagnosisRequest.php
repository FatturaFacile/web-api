<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class RmaDiagnosisRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lab_bench_diagnostic_notes' => ['required', 'string'],
            'repair_feasible' => ['required', 'boolean'],
            'parts_available' => ['nullable', 'boolean'],
            'goodwill' => ['nullable', 'boolean'],
            'lines' => ['present', 'array'],
            'lines.*.line_type' => ['required', 'string', 'in:part,labour'],
            'lines.*.product_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.defective_part_serial' => ['nullable', 'string', 'max:100'],
            'lines.*.supplier_id' => ['nullable', 'uuid'],
            'lines.*.replacement_part_serial' => ['nullable', 'string', 'max:100'],
        ];
    }
}
