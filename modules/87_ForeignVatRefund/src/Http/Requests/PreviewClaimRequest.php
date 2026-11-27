<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ForeignVatRefund\Domain\Enums\PeriodType;

/**
 * Country and period of a claim: the preview and the creation of a dossier.
 */
class PreviewClaimRequest extends FormRequest
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
            'target_country_code' => ['required', 'string', 'size:2', 'alpha'],
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'period_type' => ['required', Rule::enum(PeriodType::class)],
            'quarter' => ['nullable', 'required_if:period_type,QUARTERLY', 'integer', 'between:1,4'],
            'ledger_ids' => ['sometimes', 'array', 'max:2000'],
            'ledger_ids.*' => ['uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('target_country_code'))) {
            $this->merge(['target_country_code' => strtoupper($this->input('target_country_code'))]);
        }
    }
}
