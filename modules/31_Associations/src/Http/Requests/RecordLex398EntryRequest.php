<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordLex398EntryRequest extends FormRequest
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
        $amount = 'regex:/^-?\d{1,12}(\.\d{1,2})?$/';

        return [
            'entry_date' => ['required', 'date'],
            'document_number' => ['required', 'string', 'max:60'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'taxable_amount' => ['required', $amount],
            'vat_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount' => ['sometimes', $amount],
        ];
    }
}
