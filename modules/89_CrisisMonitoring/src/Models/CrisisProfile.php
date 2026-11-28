<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The crisis profile of the company: jurisdiction, ATECO, legal form, capital and the figures the ledgers cannot supply.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class CrisisProfile extends BaseEnterpriseModel
{
    protected $table = 'fin_crisis_profiles';

    /**
     * The profile of the current company, or the defaults of its country when nothing is stored yet.
     */
    public static function current(?string $country = null): self
    {
        return self::query()->first() ?? new self([
            'jurisdiction' => $country === 'CH' ? 'CH' : 'IT',
            'legal_form' => 'corporation',
            'employees_count' => 0,
            'going_concern_probable' => true,
        ]);
    }

    /**
     * A money field as a decimal string, or null when it is not set.
     */
    public function money(string $field): ?string
    {
        $value = $this->getAttribute($field);

        return $value === null || $value === '' ? null : (string) $value;
    }

    protected function casts(): array
    {
        return [
            'employees_count' => 'integer',
            'share_capital' => 'decimal:2',
            'statutory_reserves' => 'decimal:2',
            'legal_minimum_capital' => 'decimal:2',
            'prior_year_revenue' => 'decimal:2',
            'prior_year_inps_contributions' => 'decimal:2',
            'available_credit_lines' => 'decimal:2',
            'variable_debt_amount' => 'decimal:2',
            'total_assets_override' => 'decimal:2',
            'current_assets_override' => 'decimal:2',
            'current_liabilities_override' => 'decimal:2',
            'total_debt_override' => 'decimal:2',
            'net_equity_override' => 'decimal:2',
            'revenue_override' => 'decimal:2',
            'financial_expenses_override' => 'decimal:2',
            'cash_flow_override' => 'decimal:2',
            'tax_social_debt_override' => 'decimal:2',
            'payroll_overdue_30d_amount' => 'decimal:2',
            'supplier_overdue_90d_amount' => 'decimal:2',
            'inps_overdue_90d_amount' => 'decimal:2',
            'inail_overdue_90d_amount' => 'decimal:2',
            'vat_lipe_unpaid_amount' => 'decimal:2',
            'ader_entrusted_amount' => 'decimal:2',
            'assets_going_concern' => 'decimal:2',
            'assets_liquidation' => 'decimal:2',
            'total_liabilities_override' => 'decimal:2',
            'going_concern_probable' => 'boolean',
        ];
    }
}
