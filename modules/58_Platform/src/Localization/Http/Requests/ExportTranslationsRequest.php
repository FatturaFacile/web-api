<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportTranslationsRequest extends FormRequest
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
            'locales' => ['required', 'array', 'min:1', 'max:5'],
            'locales.*' => ['string', 'distinct', 'in:'.implode(',', $this->localeCodes())],
            'format' => ['nullable', 'string', 'in:xlsx,csv'],
        ];
    }
}
