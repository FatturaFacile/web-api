<?php

declare(strict_types=1);

namespace App\Http\Requests\RecurringContracts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceIndexRateRequest extends FormRequest
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
            'index_type' => ['required', 'string', Rule::in(['istat_foi', 'swiss_ipc'])],
            'period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'index_value' => ['required', 'numeric', 'gt:0'],
            'published_at' => ['nullable', 'date'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
