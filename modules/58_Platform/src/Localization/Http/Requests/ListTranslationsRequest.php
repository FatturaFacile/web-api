<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListTranslationsRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    public function authorize(): bool
    {
        return $this->mayRead();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.locale' => ['required', 'string', 'in:'.implode(',', $this->localeCodes())],
            'filter.group' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'filter.missing' => ['nullable', 'boolean'],
            'filter.search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
