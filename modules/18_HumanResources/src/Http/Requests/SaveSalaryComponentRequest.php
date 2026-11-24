<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A salary component of the active contract: the payroll wage type code it is paid with and the amount.
 */
final class SaveSalaryComponentRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
     */
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
            'wage_type_code' => [$required, 'string', 'max:20'],
            'description' => [$required, 'string', 'max:150'],
            'amount' => [$required, 'decimal:0,2'],
            'frequency' => ['sometimes', Rule::in(['monthly', 'annual'])],
            'valid_from' => ['sometimes', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
