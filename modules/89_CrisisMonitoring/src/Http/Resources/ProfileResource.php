<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CrisisMonitoring\Models\CrisisProfile;

/**
 * The crisis profile of the company.
 *
 * @mixin CrisisProfile
 */
final class ProfileResource extends JsonResource
{
    /** @var list<string> */
    private const array MoneyFields = [
        'share_capital', 'statutory_reserves', 'legal_minimum_capital', 'prior_year_revenue', 'prior_year_inps_contributions',
        'available_credit_lines', 'variable_debt_amount', 'total_assets_override', 'current_assets_override', 'current_liabilities_override',
        'total_debt_override', 'net_equity_override', 'revenue_override', 'financial_expenses_override', 'cash_flow_override',
        'tax_social_debt_override', 'payroll_overdue_30d_amount', 'supplier_overdue_90d_amount', 'inps_overdue_90d_amount',
        'inail_overdue_90d_amount', 'vat_lipe_unpaid_amount', 'ader_entrusted_amount', 'assets_going_concern', 'assets_liquidation',
        'total_liabilities_override',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'jurisdiction' => (string) $this->jurisdiction,
            'ateco_code' => $this->ateco_code,
            'ateco_section' => $this->ateco_section,
            'legal_form' => (string) $this->legal_form,
            'employees_count' => (int) $this->employees_count,
            'going_concern_probable' => (bool) $this->going_concern_probable,
        ];

        foreach (self::MoneyFields as $field) {
            $data[$field] = $this->money($field);
        }

        return $data;
    }
}
