<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Services\Tax\VatPeriod;

final class PreviewVatSettlementPeriodRequest extends FormRequest
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
            'period_type' => ['required', Rule::in([VatPeriod::MONTHLY, VatPeriod::QUARTERLY, VatPeriod::SEMIANNUAL])],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'period' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
