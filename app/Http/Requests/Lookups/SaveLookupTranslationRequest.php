<?php

declare(strict_types=1);

namespace App\Http\Requests\Lookups;

use Illuminate\Foundation\Http\FormRequest;

class SaveLookupTranslationRequest extends FormRequest
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
            'lookup_item_id' => ['required', 'uuid', 'exists:lookup_items,id'],
            'locale' => ['required', 'string', 'max:5'],
            'label' => ['required', 'string', 'max:255'],
            'short_label' => ['nullable', 'string', 'max:15'],
            'description' => ['nullable', 'string'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
        ];
    }
}
