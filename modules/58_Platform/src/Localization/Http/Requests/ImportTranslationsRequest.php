<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportTranslationsRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    public function authorize(): bool
    {
        return $this->mayWrite();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A csv is sniffed as text/plain by many servers, so txt is accepted as a content type and the extension decides
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'extensions:xlsx,csv', 'max:5120'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }

    public function isDryRun(): bool
    {
        return $this->boolean('dry_run');
    }
}
