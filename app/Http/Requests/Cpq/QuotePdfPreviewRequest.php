<?php

declare(strict_types=1);

namespace App\Http\Requests\Cpq;

use App\Models\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotePdfPreviewRequest extends FormRequest
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
            'show_technical_details' => ['sometimes', 'boolean'],
        ];
    }
}
