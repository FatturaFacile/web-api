<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use Illuminate\Foundation\Http\FormRequest;

class StoreTranslationRequest extends FormRequest
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
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'locale' => ['required', 'string', 'max:5'],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string'],
        ];
    }
}
