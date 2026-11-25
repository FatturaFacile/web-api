<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGdoRebateAgreementRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'agreement_code' => ['nullable', 'string', 'max:40'],
            'gdo_chain_name' => ['required', 'string', 'max:160'],
            'contract_year' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tier_brackets' => ['nullable', 'array'],
            'sell_in_volume_amount' => ['required', 'numeric', 'min:0'],
            'sell_out_promo_contribution' => ['nullable', 'numeric', 'min:0'],
            'accrued_months_count' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
