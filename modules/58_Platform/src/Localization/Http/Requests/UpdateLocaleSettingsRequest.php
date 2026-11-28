<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\ValueObjects\DateFormat;
use Modules\Platform\Localization\Domain\ValueObjects\NumberFormat;

class UpdateLocaleSettingsRequest extends FormRequest
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
        $supported = 'in:'.implode(',', SupportedLocale::values());

        return [
            'active_locales' => ['required', 'array', 'min:1'],
            'active_locales.*' => ['string', 'distinct', $supported],
            'default_locale' => ['required', 'string', $supported],
            'number_format' => ['nullable', 'array'],
            // Rule::in, not "in:a,b": the comma is itself one of the separators
            'number_format.decimal_separator' => ['required_with:number_format', 'string', Rule::in(NumberFormat::DECIMAL_SEPARATORS)],
            'number_format.thousands_separator' => ['present_with:number_format', 'nullable', 'string', Rule::in(NumberFormat::THOUSANDS_SEPARATORS)],
            'date_format' => ['nullable', 'string', Rule::in(DateFormat::PATTERNS)],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! in_array($this->input('default_locale'), (array) $this->input('active_locales'), true)) {
                $validator->errors()->add('default_locale', __('localization.validation.default_not_active'));
            }

            $number = $this->input('number_format');

            if (is_array($number) && ($number['decimal_separator'] ?? null) === ($number['thousands_separator'] ?? '')) {
                $validator->errors()->add('number_format.thousands_separator', __('localization.validation.separators_differ'));
            }
        }];
    }
}
