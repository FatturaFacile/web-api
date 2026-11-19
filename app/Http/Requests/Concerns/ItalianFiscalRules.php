<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * Validation rules of the Italian fiscal options of an invoice payload (issue #286), shared by invoice creation and
 * the fiscal preview. `fund`, `withholding` and `enasarco`: absent = tenant default, null = disabled.
 */
trait ItalianFiscalRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function italianFiscalRules(): array
    {
        $natura = Rule::in((array) config('italian_tax.natura_codes', []));

        return [
            'vat_payability' => ['nullable', 'string', Rule::in(['I', 'D', 'S'])],
            'items.*.natura' => ['nullable', 'string', $natura],
            'items.*.withholding_applies' => ['nullable', 'boolean'],
            'items.*.fund_applies' => ['nullable', 'boolean'],

            'fund' => ['nullable', 'array'],
            'fund.type' => ['required_with:fund', 'string', Rule::in(array_keys((array) config('italian_tax.fund_types', [])))],
            'fund.percent' => ['required_with:fund', 'numeric', 'min:0', 'max:100'],
            'fund.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fund.natura' => ['nullable', 'string', $natura],
            'fund.withholding_applies' => ['nullable', 'boolean'],

            'withholding' => ['nullable', 'array'],
            'withholding.type' => ['required_with:withholding', 'string', Rule::in(array_keys((array) config('italian_tax.withholding.types', [])))],
            'withholding.reason' => ['required_with:withholding', 'string', Rule::in((array) config('italian_tax.withholding.reasons', []))],
            'withholding.rate' => ['required_with:withholding', 'numeric', 'min:0', 'max:100'],
            'withholding.taxable_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'withholding.includes_fund' => ['nullable', 'boolean'],

            'enasarco' => ['nullable', 'array'],
            'enasarco.enabled' => ['nullable', 'boolean'],
            'enasarco.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'enasarco.reason' => ['nullable', 'string', Rule::in((array) config('italian_tax.withholding.reasons', []))],

            'stamp_duty_charged_to_customer' => ['nullable', 'boolean'],
        ];
    }
}
