<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Platform\Localization\Domain\ValueObjects\TranslationKey;

class UpsertTranslationRequest extends FormRequest
{
    use ResolvesLocalizationScope;

    public function authorize(): bool
    {
        return $this->mayWrite();
    }

    /**
     * The full key is the group and the key of the URL.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->route('group').'.'.$this->route('key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'in:'.implode(',', $this->localeCodes())],
            'value' => ['required', 'string', 'max:5000'],
            'key' => ['required', 'string', 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! TranslationKey::isValid($value)) {
                    $fail(__('localization.validation.key_format'));
                }
            }],
        ];
    }

    public function translationKey(): TranslationKey
    {
        return TranslationKey::from((string) $this->validated('key'));
    }
}
