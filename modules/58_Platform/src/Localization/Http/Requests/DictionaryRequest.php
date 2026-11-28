<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DictionaryRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    /**
     * The dictionary is public: the login page needs it before anybody is signed in.
     */
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
            'locale' => ['required', 'string', 'in:'.implode(',', $this->localeCodes())],
        ];
    }
}
