<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Enums\TranslatableResource;

class UpsertResourceTranslationRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    public function authorize(): bool
    {
        return $this->mayWrite();
    }

    public function resource(): TranslatableResource
    {
        return TranslatableResource::from((string) $this->route('resource'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'translations' => ['required', 'array', 'min:1'],
            'translations.*' => ['array'],
            'translations.*.*' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The locales are the keys of the payload: each must be a supported locale and carry only fields of the resource.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = $this->resource()->fields();

            foreach ((array) $this->input('translations', []) as $locale => $values) {
                if (SupportedLocale::tryFrom((string) $locale) === null) {
                    $validator->errors()->add('translations', __('localization.validation.unknown_locale', ['locale' => (string) $locale]));

                    continue;
                }

                foreach (array_keys((array) $values) as $field) {
                    if (! in_array($field, $fields, true)) {
                        $validator->errors()->add('translations', __('localization.validation.unknown_field', ['field' => (string) $field]));
                    }
                }
            }
        }];
    }
}
