<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveReportRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => ['nullable', 'string', 'max:200'],
            'employee_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'period_start' => [$required, 'date'],
            'period_end' => [$required, 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
