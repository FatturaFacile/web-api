<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Enums\RoundingMode;

final class StoreDossierRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'fiscal_year_id' => [$required, 'uuid'],
            'comparative_prior_year_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'accounting_standard' => [$required, Rule::in(AccountingStandard::values())],
            'rounding_to_unit_mode' => ['sometimes', Rule::in(RoundingMode::values())],
            'income_statement_method' => ['sometimes', Rule::in(IncomeStatementMethod::values())],
            'cash_flow_method' => ['sometimes', Rule::in(CashFlowMethod::values())],
            'locale' => ['sometimes', Rule::in(['it', 'en', 'de', 'fr'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'share_capital' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.cogs_allocation' => ['sometimes', 'array'],
            'options.cogs_allocation.personnel' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'options.cogs_allocation.other_opex' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'options.cogs_allocation.depr' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'dividends' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'other_reserves' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'carried_forward' => ['sometimes', 'nullable', 'numeric'],
            'hidden_reserves_config' => ['sometimes', 'nullable', 'array'],
            'hidden_reserves_config.enabled' => ['sometimes', 'boolean'],
            'hidden_reserves_config.tax_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'hidden_reserves_config.reserves_created' => ['sometimes', 'numeric', 'min:0'],
            'hidden_reserves_config.reserves_released' => ['sometimes', 'numeric', 'min:0'],
            'hidden_reserves_config.items' => ['sometimes', 'array', 'max:50'],
            'hidden_reserves_config.items.*.label' => ['required_with:hidden_reserves_config.items', 'string', 'max:160'],
            'hidden_reserves_config.items.*.book_value' => ['required_with:hidden_reserves_config.items', 'numeric'],
            'hidden_reserves_config.items.*.fer_value' => ['required_with:hidden_reserves_config.items', 'numeric'],
        ];
    }
}
