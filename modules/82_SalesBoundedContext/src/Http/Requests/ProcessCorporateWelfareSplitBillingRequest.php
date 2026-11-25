<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SalesBoundedContext\Domain\Enums\WelfareFiscalRegime;

final class ProcessCorporateWelfareSplitBillingRequest extends FormRequest
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
            'partner_dossier_id' => ['nullable', 'uuid'],
            'convention_code' => ['nullable', 'string', 'max:40'],
            'partner_company_name' => ['required', 'string', 'max:160'],
            'corporate_email_domain' => ['required', 'string', 'max:120'],
            'employee_email' => ['required', 'email', 'max:180'],
            'cral_badge_number' => ['required', 'string', 'max:64'],
            'welfare_regime' => ['required', Rule::in(WelfareFiscalRegime::values())],
            'voucher_provider' => ['nullable', 'string', 'max:64'],
            'gross_basket_amount' => ['required', 'numeric', 'min:0'],
            'convention_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'employer_welfare_split_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'regime_annual_limit' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
