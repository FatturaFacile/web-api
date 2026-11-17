<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class FiscalLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'in:IT,CH'],
            'vat_number' => ['required_without:uid_number', 'nullable', 'string', 'max:30'],
            'uid_number' => ['required_without:vat_number', 'nullable', 'string', 'max:30'],
        ];
    }
}
