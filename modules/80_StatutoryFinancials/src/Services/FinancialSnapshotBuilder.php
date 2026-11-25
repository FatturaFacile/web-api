<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Contracts\DueDateSchedulePort;
use Modules\StatutoryFinancials\Contracts\LeaseRegisterPort;
use Modules\StatutoryFinancials\Contracts\WorkforceRegisterPort;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Domain\Strategy\EntityMetrics;
use Modules\StatutoryFinancials\Domain\Strategy\FinancialSnapshot;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Collects the figures of the ratios and of the six-month DSCR: the rounded statements plus the debt service read from the
 * subledgers (open supplier items and lease payments falling due within the horizon, and the financial debt falling due).
 */
final class FinancialSnapshotBuilder
{
    public function __construct(
        private readonly DueDateSchedulePort $dueDates,
        private readonly LeaseRegisterPort $leases,
        private readonly WorkforceRegisterPort $workforce,
    ) {}

    public function entityMetrics(StatutoryDossier $dossier, StatementBundle $bundle): EntityMetrics
    {
        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $headcount = $this->workforce->averageHeadcount((string) $dossier->tenant_id, CarbonImmutable::parse($year->start_date), CarbonImmutable::parse($year->end_date));
        $family = $bundle->standard->jurisdiction();
        $revenueCode = $family === 'IT' ? 'it.is.a.1' : ($bundle->method->value === 'cost_of_sales' ? 'ch.is.cs.rev' : 'ch.is.tc.rev');

        return new EntityMetrics(
            totalAssets: $bundle->quadrature->balanceSheetTotals[$bundle->codes['bs_assets']] ?? '0',
            revenue: $bundle->quadrature->incomeStatementTotals[$revenueCode] ?? '0',
            averageEmployees: $family === 'CH' ? $headcount['total_fte'] : $headcount['total_headcount'],
        );
    }

    public function build(StatutoryDossier $dossier, StatementBundle $bundle): FinancialSnapshot
    {
        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);
        $tenantId = (string) $dossier->tenant_id;
        $bs = $bundle->quadrature->balanceSheetTotals;
        $is = $bundle->quadrature->incomeStatementTotals;
        $family = $bundle->standard->jurisdiction();
        $cs = $bundle->method->value === 'cost_of_sales';
        $b = static fn (string $code): string => Money::from($bs[$code] ?? '0');
        $i = static fn (string $code): string => Money::from($is[$code] ?? '0');

        if ($family === 'IT') {
            $values = [
                'revenue' => $i('it.is.a.1'), 'ebit' => $i('it.is.diff'), 'net_profit' => $i('it.is.profit'), 'financial_charges' => $i('it.is.c.17'),
                'depreciation' => Money::round($bundle->classification->incomeLeaves['it.is.b.10'] ?? '0', $bundle->scale),
                'total_assets' => $b('it.bs.assets'), 'equity' => $b('it.bs.pn'), 'share_capital' => $b('it.bs.pn.i'),
                'current_assets' => $b('it.bs.c'), 'inventories' => $b('it.bs.c.i'), 'cash' => $b('it.bs.c.iv'),
                'current_liabilities' => $b('it.bs.deb'), 'total_debt' => $b('it.bs.deb'),
                'financial_debt' => Money::add($b('it.bs.deb.4'), $b('it.bs.deb.5')), 'tax_social_debt' => Money::add($b('it.bs.deb.12'), $b('it.bs.deb.13')),
                'short_term_financial_debt' => Money::add($b('it.bs.deb.4'), $b('it.bs.deb.5')),
                'tax' => $i('it.is.tax'),
            ];
        } else {
            $prefix = $cs ? 'ch.is.cs.' : 'ch.is.tc.';
            $values = [
                'revenue' => $i($prefix.'rev'), 'ebit' => $i($prefix.'ebit'), 'net_profit' => $i($prefix.'profit'), 'financial_charges' => $i($prefix.'fin_exp'),
                'depreciation' => Money::round($bundle->classification->incomeLeaves['ch.is.tc.depr'] ?? '0', $bundle->scale),
                'total_assets' => $b('ch.bs.assets'), 'equity' => $b('ch.bs.equity'), 'share_capital' => $b('ch.bs.share_capital'),
                'current_assets' => $b('ch.bs.ca'), 'inventories' => $b('ch.bs.inventory'), 'cash' => $b('ch.bs.cash'),
                'current_liabilities' => $b('ch.bs.stl'), 'total_debt' => $b('ch.bs.debt'),
                'financial_debt' => Money::add(Money::add($b('ch.bs.st_debt'), $b('ch.bs.lt_debt')), $b('ch.bs.other_lt_pay')), 'tax_social_debt' => '0',
                'short_term_financial_debt' => $b('ch.bs.st_debt'),
                'legal_capital_base' => Money::add(Money::add($b('ch.bs.share_capital'), $b('ch.bs.legal_cap_res')), $b('ch.bs.legal_profit_res')),
                'tax' => $i($prefix.'tax'),
            ];
        }

        $horizon = (string) config('statutory_financials.dscr_horizon_months', 6);
        $ratio = Money::div($horizon, '12') ?? '0.5';
        $ebitda = Money::add($values['ebit'], $values['depreciation']);
        $values['expected_cash_flow_6m'] = Money::mul(Money::sub($ebitda, $values['tax']), $ratio);
        $values['opening_liquidity'] = $values['cash'];

        $maturities = $this->dueDates->maturities($tenantId, $to);
        $leases = $this->leases->commitments($tenantId, $from, $to);
        $financialService = Money::mul($values['short_term_financial_debt'], $ratio);
        $values['debt_service_payables'] = $maturities['payables_due_6m'];
        $values['debt_service_leases'] = $leases['due_6m'];
        $values['debt_service_financial'] = $financialService;
        $values['debt_service_6m'] = Money::add(Money::add($maturities['payables_due_6m'], $leases['due_6m']), $financialService);

        return new FinancialSnapshot($values, $dossier->locale);
    }
}
