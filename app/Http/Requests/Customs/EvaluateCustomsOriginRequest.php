<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluateCustomsOriginRequest extends FormRequest
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
            'locale' => ['nullable', Rule::in(['it', 'de', 'fr', 'en'])],
            'customs_auth_number' => ['nullable', 'string', 'max:50'],
            'apply' => ['sometimes', 'boolean'],
        ];
    }
}
