<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ExpenseManagement\Domain\Enums\AllowanceMethod;
use Modules\ExpenseManagement\Domain\Enums\DestinationType;

final class PerDiemRequest extends FormRequest
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
            'country_code' => ['required', 'string', 'size:2'],
            'destination_type' => ['required', Rule::in(DestinationType::values())],
            'allowance_method' => ['required', Rule::in(AllowanceMethod::values())],
            'trip_start_at' => ['required', 'date'],
            'trip_end_at' => ['required', 'date', 'after:trip_start_at'],
            'allowance_rate_per_day' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'lodging_reimbursed' => ['nullable', 'boolean'],
            'meals_reimbursed' => ['nullable', 'boolean'],
        ];
    }
}
