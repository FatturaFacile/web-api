<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReconcileCardRequest extends FormRequest
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
            'action' => ['required', Rule::in(['match', 'unmatch', 'ignore', 'dispute'])],
            'matches' => ['required_if:action,match', 'array', 'max:50'],
            'matches.*.expense_line_id' => ['required', 'uuid'],
            'matches.*.amount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
