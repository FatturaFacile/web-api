<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordFivePerMilleRequest extends FormRequest
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
            'reference_year' => ['required', 'string', 'max:20'],
            'received_on' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'payment_method' => ['sometimes', Rule::in(['cash', 'bank'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
