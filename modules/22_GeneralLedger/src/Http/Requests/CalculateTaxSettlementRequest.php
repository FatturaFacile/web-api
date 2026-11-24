<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Services\Tax\VatPeriod;

final class CalculateTaxSettlementRequest extends FormRequest
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
            'fiscal_period_id' => ['nullable', 'uuid', 'required_without_all:year,period'],
            'period_type' => ['nullable', Rule::in([VatPeriod::MONTHLY, VatPeriod::QUARTERLY, VatPeriod::SEMIANNUAL])],
            'year' => ['nullable', 'integer', 'between:2000,2100', 'required_without:fiscal_period_id'],
            'period' => ['nullable', 'integer', 'between:1,12', 'required_without:fiscal_period_id'],
        ];
    }
}
