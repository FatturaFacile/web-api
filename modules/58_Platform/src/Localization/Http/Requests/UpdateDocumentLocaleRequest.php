<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

class UpdateDocumentLocaleRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'in:'.implode(',', SupportedLocale::values())],
        ];
    }
}
