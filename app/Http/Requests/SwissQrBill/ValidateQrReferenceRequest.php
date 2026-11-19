<?php

declare(strict_types=1);

namespace App\Http\Requests\SwissQrBill;

use Illuminate\Foundation\Http\FormRequest;

class ValidateQrReferenceRequest extends FormRequest
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
            'iban' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:40'],
        ];
    }
}
