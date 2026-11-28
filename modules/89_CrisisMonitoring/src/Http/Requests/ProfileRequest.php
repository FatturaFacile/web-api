<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CrisisMonitoring\Support\AtecoSection;

/**
 * The crisis profile of the company: jurisdiction, ATECO, legal form, capital and the figures the ledgers cannot supply.
 */
final class ProfileRequest extends FormRequest
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
        $amount = ['nullable', 'numeric', 'min:0', 'max:999999999999'];
        $signed = ['nullable', 'numeric', 'min:-999999999999', 'max:999999999999'];

        return [
            'jurisdiction' => ['sometimes', Rule::in(['IT', 'CH'])],
            'ateco_code' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^\d{2}([.\d]{0,9})$/'],
            'ateco_section' => ['sometimes', 'nullable', Rule::in(AtecoSection::Sections)],
            'legal_form' => ['sometimes', Rule::in(['corporation', 'partnership', 'sole_proprietorship'])],
            'employees_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'share_capital' => ['sometimes', ...$amount],
            'statutory_reserves' => ['sometimes', ...$amount],
            'legal_minimum_capital' => ['sometimes', ...$amount],
            'prior_year_revenue' => ['sometimes', ...$amount],
            'prior_year_inps_contributions' => ['sometimes', ...$amount],
            'available_credit_lines' => ['sometimes', ...$amount],
            'variable_debt_amount' => ['sometimes', ...$amount],
            'total_assets_override' => ['sometimes', ...$amount],
            'current_assets_override' => ['sometimes', ...$amount],
            'current_liabilities_override' => ['sometimes', ...$amount],
            'total_debt_override' => ['sometimes', ...$amount],
            'net_equity_override' => ['sometimes', ...$signed],
            'revenue_override' => ['sometimes', ...$amount],
            'financial_expenses_override' => ['sometimes', ...$amount],
            'cash_flow_override' => ['sometimes', ...$signed],
            'tax_social_debt_override' => ['sometimes', ...$amount],
            'payroll_overdue_30d_amount' => ['sometimes', ...$amount],
            'supplier_overdue_90d_amount' => ['sometimes', ...$amount],
            'inps_overdue_90d_amount' => ['sometimes', ...$amount],
            'inail_overdue_90d_amount' => ['sometimes', ...$amount],
            'vat_lipe_unpaid_amount' => ['sometimes', ...$amount],
            'ader_entrusted_amount' => ['sometimes', ...$amount],
            'assets_going_concern' => ['sometimes', ...$amount],
            'assets_liquidation' => ['sometimes', ...$amount],
            'total_liabilities_override' => ['sometimes', ...$amount],
            'going_concern_probable' => ['sometimes', 'boolean'],
        ];
    }
}
