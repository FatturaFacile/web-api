<?php

declare(strict_types=1);

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class ViesValidateRequest extends FormRequest
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
            'vat_number' => ['required', 'string', 'max:30'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'contact_id' => ['nullable', 'uuid'],
        ];
    }
}
