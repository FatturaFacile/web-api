<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use App\Models\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSignatureLinkRequest extends FormRequest
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
            'locale' => ['required', Rule::in(Translation::SUPPORTED_LOCALES)],
            'validity_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
        ];
    }
}
