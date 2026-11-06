<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The filters of the lists by employee. The parameter is employee_id: user_id and tenant_id are reserved for the
 * request context.
 */
final class ListEmployeeDocumentsRequest extends FormRequest
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
            'employee_id' => ['nullable', 'uuid'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
