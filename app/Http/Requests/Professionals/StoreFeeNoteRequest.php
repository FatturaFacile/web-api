<?php

declare(strict_types=1);

namespace App\Http\Requests\Professionals;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeeNoteRequest extends FormRequest
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
            'legal_case_id' => ['nullable', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'note_number' => ['required', 'string'],
            'date' => ['required', 'date'],
            'taxable_fees' => ['required', 'numeric', 'min:0'],
            'cpa_rate' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'ritenuta_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:25'],
        ];
    }
}
