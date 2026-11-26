<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Enums\VatRateMethod;

final class UpdateCurrencySettingsRequest extends FormRequest
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
            'active_currencies' => ['required', 'array', 'min:1'],
            'active_currencies.*' => ['string', 'size:3', 'alpha'],
            'alert_threshold_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'unrealized_gain_treatment' => ['required', 'string', Rule::enum(UnrealizedGainTreatment::class)],
            'vat_rate_method' => ['sometimes', 'nullable', 'string', Rule::enum(VatRateMethod::class)],
            'revaluation_accounts' => ['sometimes', 'nullable', 'array'],
            'revaluation_accounts.unrealized_gain' => ['nullable', 'uuid'],
            'revaluation_accounts.unrealized_loss' => ['nullable', 'uuid'],
            'revaluation_accounts.unrealized_gain_provision' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $threshold = $this->input('alert_threshold_percent');

        if (is_int($threshold) || is_float($threshold)) {
            $this->merge(['alert_threshold_percent' => (string) $threshold]);
        }
    }
}
