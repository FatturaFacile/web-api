<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\PurchaseRequisition\Services;

use App\Domain\Procurement\PurchaseRequisition\Contracts\BudgetAvailabilityGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\BudgetEnforcementPolicy;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\CostCenterBudgetLineCheck;
use App\Domain\Procurement\Rfq\Contracts\ExchangeRateResolverInterface;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Services\ProcurementSettingsService;
use Modules\Procurement\Support\Decimal;

/**
 * Infrastructure adapter checking real-time cost center budget availability across Mod 53 / Mod 64 / Mod 22 / Mod 19
 * (HR cost centers) and combining posted actuals + open PR/PO commitments.
 */
final readonly class Mod019BudgetAvailabilityAdapter implements BudgetAvailabilityGatewayInterface
{
    public function __construct(
        private ProcurementSettingsService $procurementSettings,
        private ExchangeRateResolverInterface $exchangeRates,
    ) {}

    public function resolveEnforcementPolicy(string $tenantId): BudgetEnforcementPolicy
    {
        $settings = $this->procurementSettings->forTenant($tenantId);
        $configured = $settings->budget_enforcement_policy;

        if (is_string($configured) && $configured !== '') {
            return BudgetEnforcementPolicy::tryFrom($configured) ?? BudgetEnforcementPolicy::Warning;
        }

        $tenant = Tenant::query()->find($tenantId);
        $tenantSettings = is_array($tenant?->settings) ? $tenant->settings : [];
        $prefPolicy = $tenantSettings['preferences']['procurement']['budget_enforcement_policy']
            ?? $tenantSettings['budget_enforcement_policy']
            ?? config('procurement_requisitions.default_budget_enforcement_policy', BudgetEnforcementPolicy::Warning->value);

        return BudgetEnforcementPolicy::tryFrom((string) $prefPolicy) ?? BudgetEnforcementPolicy::Warning;
    }

    public function checkAvailability(
        string $tenantId,
        array $amountsByCostCenter,
        string $currency,
        int $fiscalYear,
        ?string $excludeRequisitionId = null,
    ): BudgetCheckResult {
        $prCurrency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'EUR';
        $policy = $this->resolveEnforcementPolicy($tenantId);
        $checkedAt = CarbonImmutable::now('UTC')->toIso8601String();

        $totalAllocated = '0.00';
        $totalActual = '0.00';
        $totalCommitted = '0.00';
        $totalResidual = '0.00';
        $totalRequested = '0.00';
        $totalProjectedResidual = '0.00';
        $totalOverage = '0.00';
        $anyConfigured = false;
        $anyOverBudget = false;
        $lineChecks = [];

        foreach ($amountsByCostCenter as $costCenterId => $requestedOriginalRaw) {
            $requestedOriginal = Decimal::of($requestedOriginalRaw, 2);
            $check = $this->evaluateSingleCostCenter(
                tenantId: $tenantId,
                costCenterId: (string) $costCenterId,
                requestedOriginal: $requestedOriginal,
                prCurrency: $prCurrency,
                fiscalYear: $fiscalYear,
                excludeRequisitionId: $excludeRequisitionId,
            );

            $lineChecks[] = $check;

            if ($check->hasConfiguredBudget) {
                $anyConfigured = true;
            }

            if ($check->isOverBudget) {
                $anyOverBudget = true;
            }

            // Aggregate totals normalized to PR currency
            $rateToPr = $this->exchangeRates->resolveRate($tenantId, $check->budgetCurrency, $prCurrency);
            $totalAllocated = Decimal::add($totalAllocated, Decimal::mul($check->allocatedBudget, $rateToPr, 2), 2);
            $totalActual = Decimal::add($totalActual, Decimal::mul($check->actualSpent, $rateToPr, 2), 2);
            $totalCommitted = Decimal::add($totalCommitted, Decimal::mul($check->committedAmount, $rateToPr, 2), 2);
            $totalResidual = Decimal::add($totalResidual, Decimal::mul($check->residualAvailable, $rateToPr, 2), 2);
            $totalRequested = Decimal::add($totalRequested, $requestedOriginal, 2);
            $totalProjectedResidual = Decimal::add($totalProjectedResidual, Decimal::mul($check->projectedResidual, $rateToPr, 2), 2);
            $totalOverage = Decimal::add($totalOverage, Decimal::mul($check->overageAmount, $rateToPr, 2), 2);
        }

        return new BudgetCheckResult(
            currency: $prCurrency,
            fiscalYear: $fiscalYear,
            enforcementPolicy: $policy,
            allocatedBudget: $totalAllocated,
            actualSpent: $totalActual,
            committedAmount: $totalCommitted,
            residualAvailable: $totalResidual,
            requestedAmount: $totalRequested,
            projectedResidual: $totalProjectedResidual,
            isOverBudget: $anyOverBudget,
            overageAmount: $totalOverage,
            hasConfiguredBudget: $anyConfigured,
            checkedAt: $checkedAt,
            costCenters: $lineChecks,
        );
    }

    private function evaluateSingleCostCenter(
        string $tenantId,
        string $costCenterId,
        string $requestedOriginal,
        string $prCurrency,
        int $fiscalYear,
        ?string $excludeRequisitionId,
    ): CostCenterBudgetLineCheck {
        if ($costCenterId === '__UNASSIGNED__' || $costCenterId === '') {
            return new CostCenterBudgetLineCheck(
                costCenterId: $costCenterId,
                costCenterCode: null,
                costCenterName: 'Unassigned',
                budgetCurrency: $prCurrency,
                prCurrency: $prCurrency,
                exchangeRateApplied: '1.000000',
                fiscalYear: $fiscalYear,
                hasConfiguredBudget: false,
                allocatedBudget: '0.00',
                actualSpent: '0.00',
                committedAmount: '0.00',
                residualAvailable: '0.00',
                requestedAmountOriginal: $requestedOriginal,
                requestedAmountInBudgetCurrency: $requestedOriginal,
                projectedResidual: Decimal::sub('0.00', $requestedOriginal, 2),
                isOverBudget: false,
                overageAmount: '0.00',
                source: 'none',
            );
        }

        $meta = $this->resolveCostCenterMeta($tenantId, $costCenterId);
        $allocation = $this->resolveBudgetAllocation($tenantId, $costCenterId, $fiscalYear, $prCurrency);
        $budgetCurrency = $allocation['currency'];
        $rate = $this->exchangeRates->resolveRate($tenantId, $prCurrency, $budgetCurrency);
        $requestedInBudgetCurrency = Decimal::mul($requestedOriginal, $rate, 2);

        $actualSpent = $this->computeActualSpent($tenantId, $costCenterId, $fiscalYear, $allocation['source']);
        $committedAmount = $this->computeCommittedAmount($tenantId, $costCenterId, $fiscalYear, $budgetCurrency, $excludeRequisitionId);

        $allocated = $allocation['allocated'];
        $consumed = Decimal::add($actualSpent, $committedAmount, 2);
        $residual = Decimal::sub($allocated, $consumed, 2);
        $projectedResidual = Decimal::sub($residual, $requestedInBudgetCurrency, 2);

        $isOverBudget = $allocation['has_budget'] && Decimal::cmp($projectedResidual, '0.00', 2) < 0;
        $overageAmount = $isOverBudget ? Decimal::abs($projectedResidual, 2) : '0.00';

        return new CostCenterBudgetLineCheck(
            costCenterId: $costCenterId,
            costCenterCode: $meta['code'],
            costCenterName: $meta['name'],
            budgetCurrency: $budgetCurrency,
            prCurrency: $prCurrency,
            exchangeRateApplied: $rate,
            fiscalYear: $fiscalYear,
            hasConfiguredBudget: $allocation['has_budget'],
            allocatedBudget: $allocated,
            actualSpent: $actualSpent,
            committedAmount: $committedAmount,
            residualAvailable: $residual,
            requestedAmountOriginal: $requestedOriginal,
            requestedAmountInBudgetCurrency: $requestedInBudgetCurrency,
            projectedResidual: $projectedResidual,
            isOverBudget: $isOverBudget,
            overageAmount: $overageAmount,
            source: $allocation['source'],
        );
    }

    /**
     * @return array{code: ?string, name: ?string}
     */
    private function resolveCostCenterMeta(string $tenantId, string $costCenterId): array
    {
        foreach (['cost_centers', 'analytical_cost_centers', 'hr_cost_centers'] as $table) {
            $row = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('id', $costCenterId)
                ->whereNull('deleted_at')
                ->first(['code', 'name']);

            if ($row !== null) {
                return [
                    'code' => $row->code !== null ? (string) $row->code : null,
                    'name' => $row->name !== null ? (string) $row->name : null,
                ];
            }
        }

        return ['code' => null, 'name' => null];
    }

    /**
     * Resolves allocated budget in priority order across Mod 53, Mod 64, Mod 22, and HR cost centers.
     *
     * @return array{has_budget: bool, allocated: string, currency: string, source: string}
     */
    private function resolveBudgetAllocation(
        string $tenantId,
        string $costCenterId,
        int $fiscalYear,
        string $fallbackCurrency,
    ): array {
        // 1. Mod 53 Budgeting (budget_versions + budget_lines)
        $version = DB::table('budget_versions')
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->where('version_type', 'budget')
            ->whereIn('status', ['locked', 'approved'])
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'locked' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('approved_at')
            ->orderByDesc('created_at')
            ->first(['id', 'currency']);

        if ($version !== null) {
            $mod53Rows = DB::table('budget_lines as bl')
                ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'bl.account_id')
                ->where('bl.tenant_id', $tenantId)
                ->where('bl.version_id', $version->id)
                ->where('bl.cost_center_id', $costCenterId)
                ->whereNull('bl.deleted_at')
                ->where(static function ($q): void {
                    $q->whereNull('coa.id')
                        ->orWhereNotIn('coa.account_type', ['revenue'])
                        ->whereNotIn('coa.account_class', ['revenue']);
                })
                ->get(['bl.amount']);

            if ($mod53Rows->isNotEmpty()) {
                $sum = '0.00';
                foreach ($mod53Rows as $row) {
                    $sum = Decimal::add($sum, Decimal::of((string) $row->amount, 2), 2);
                }

                return [
                    'has_budget' => true,
                    'allocated' => $sum,
                    'currency' => strtoupper((string) ($version->currency ?: $fallbackCurrency)),
                    'source' => 'mod_053_budgeting',
                ];
            }
        }

        // 2. Mod 64 Controlling (controlling_budget_plans + controlling_budget_lines)
        $plan = DB::table('controlling_budget_plans')
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->where('plan_type', 'annual_budget')
            ->whereIn('status', ['locked', 'approved'])
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'locked' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('version_number')
            ->orderByDesc('created_at')
            ->first(['id', 'currency_code']);

        if ($plan !== null) {
            $controllingRows = DB::table('controlling_budget_lines')
                ->where('tenant_id', $tenantId)
                ->where('plan_id', $plan->id)
                ->where('cost_center_id', $costCenterId)
                ->where('nature_type', '<>', 'revenue')
                ->whereNull('deleted_at')
                ->get(['annual_total']);

            if ($controllingRows->isNotEmpty()) {
                $sum = '0.00';
                foreach ($controllingRows as $row) {
                    $sum = Decimal::add($sum, Decimal::of((string) $row->annual_total, 2), 2);
                }

                return [
                    'has_budget' => true,
                    'allocated' => $sum,
                    'currency' => strtoupper((string) ($plan->currency_code ?: $fallbackCurrency)),
                    'source' => 'mod_064_controlling',
                ];
            }
        }

        // 3. Mod 22 General Ledger (gl_budgets + gl_budget_lines)
        $glBudget = DB::table('gl_budgets as gb')
            ->join('fiscal_years as fy', 'fy.id', '=', 'gb.fiscal_year_id')
            ->where('gb.tenant_id', $tenantId)
            ->where('gb.status', 'approved')
            ->whereNull('gb.deleted_at')
            ->where('fy.start_date', '<=', "{$fiscalYear}-12-31")
            ->where('fy.end_date', '>=', "{$fiscalYear}-01-01")
            ->orderByDesc('gb.version')
            ->orderByDesc('gb.approved_at')
            ->first(['gb.id']);

        if ($glBudget !== null) {
            $glRows = DB::table('gl_budget_lines as gbl')
                ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'gbl.account_id')
                ->where('gbl.tenant_id', $tenantId)
                ->where('gbl.budget_id', $glBudget->id)
                ->where('gbl.cost_center_id', $costCenterId)
                ->whereNull('gbl.deleted_at')
                ->where(static function ($q): void {
                    $q->whereNull('coa.id')
                        ->orWhereNotIn('coa.account_type', ['revenue'])
                        ->whereNotIn('coa.account_class', ['revenue']);
                })
                ->get(['gbl.amount']);

            if ($glRows->isNotEmpty()) {
                $sum = '0.00';
                foreach ($glRows as $row) {
                    $sum = Decimal::add($sum, Decimal::of((string) $row->amount, 2), 2);
                }

                return [
                    'has_budget' => true,
                    'allocated' => $sum,
                    'currency' => $this->tenantDefaultCurrency($tenantId, $fallbackCurrency),
                    'source' => 'mod_022_gl_budget',
                ];
            }
        }

        // 4. Mod 19 / HR Cost Centers annual budget
        $hrCostCenter = DB::table('hr_cost_centers')
            ->where('tenant_id', $tenantId)
            ->where('id', $costCenterId)
            ->whereNull('deleted_at')
            ->first(['annual_budget']);

        if ($hrCostCenter !== null && Decimal::cmp((string) $hrCostCenter->annual_budget, '0', 2) > 0) {
            return [
                'has_budget' => true,
                'allocated' => Decimal::of((string) $hrCostCenter->annual_budget, 2),
                'currency' => $this->tenantDefaultCurrency($tenantId, $fallbackCurrency),
                'source' => 'hr_cost_center',
            ];
        }

        return [
            'has_budget' => false,
            'allocated' => '0.00',
            'currency' => $fallbackCurrency,
            'source' => 'none',
        ];
    }

    private function computeActualSpent(string $tenantId, string $costCenterId, int $fiscalYear, string $source): string
    {
        if ($source === 'mod_064_controlling') {
            $analyticalRows = DB::table('analytical_ledger_entries')
                ->where('tenant_id', $tenantId)
                ->where('fiscal_year', $fiscalYear)
                ->where('cost_center_id', $costCenterId)
                ->where('nature_type', '<>', 'revenue')
                ->whereNull('deleted_at')
                ->get(['amount']);

            $total = '0.00';
            foreach ($analyticalRows as $row) {
                $total = Decimal::add($total, Decimal::of((string) $row->amount, 2), 2);
            }

            if (Decimal::cmp($total, '0.00', 2) !== 0) {
                return $total;
            }
        }

        $journalRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('jl.tenant_id', $tenantId)
            ->where('jl.cost_center_id', $costCenterId)
            ->whereNull('jl.deleted_at')
            ->whereNull('je.deleted_at')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereDate('je.posting_date', '>=', "{$fiscalYear}-01-01")
            ->whereDate('je.posting_date', '<=', "{$fiscalYear}-12-31")
            ->where(static function ($q): void {
                $q->where('coa.account_type', 'expense')
                    ->orWhere('coa.account_class', 'expense');
            })
            ->get(['jl.debit_amount', 'jl.credit_amount']);

        $total = '0.00';
        foreach ($journalRows as $row) {
            $net = Decimal::sub(
                Decimal::of((string) $row->debit_amount, 2),
                Decimal::of((string) $row->credit_amount, 2),
                2
            );
            $total = Decimal::add($total, $net, 2);
        }

        return Decimal::cmp($total, '0.00', 2) > 0 ? $total : '0.00';
    }

    private function computeCommittedAmount(
        string $tenantId,
        string $costCenterId,
        int $fiscalYear,
        string $budgetCurrency,
        ?string $excludeRequisitionId,
    ): string {
        $committed = '0.00';

        // Unconverted amounts from open/approved Purchase Requisitions
        $prLines = DB::table('purchase_requisition_lines as prl')
            ->join('purchase_requisitions as pr', 'pr.id', '=', 'prl.purchase_requisition_id')
            ->where('pr.tenant_id', $tenantId)
            ->whereNull('pr.deleted_at')
            ->whereNull('prl.deleted_at')
            ->whereIn('pr.status', [
                RequisitionStatus::PendingApproval->value,
                RequisitionStatus::Approved->value,
                RequisitionStatus::PartiallyConverted->value,
                'sourcing',
            ])
            ->when($excludeRequisitionId !== null, static fn ($q) => $q->where('pr.id', '<>', $excludeRequisitionId))
            ->where(static function ($q) use ($costCenterId): void {
                $q->where('prl.cost_center_id', $costCenterId)
                    ->orWhere(static function ($nested) use ($costCenterId): void {
                        $nested->whereNull('prl.cost_center_id')
                            ->where('pr.cost_center_id', $costCenterId);
                    });
            })
            ->where('pr.required_by_date', '>=', "{$fiscalYear}-01-01")
            ->where('pr.required_by_date', '<=', "{$fiscalYear}-12-31")
            ->get([
                'pr.currency',
                'prl.quantity',
                'prl.quantity_requested',
                'prl.converted_quantity',
                'prl.estimated_unit_price',
            ]);

        foreach ($prLines as $row) {
            $qty = Decimal::of((string) ($row->quantity ?? $row->quantity_requested ?? '0'), 4);
            $converted = Decimal::of((string) ($row->converted_quantity ?? '0'), 4);
            $remaining = Decimal::sub($qty, $converted, 4);

            if (Decimal::cmp($remaining, '0', 4) <= 0) {
                continue;
            }

            $amount = Decimal::mul($remaining, Decimal::of((string) ($row->estimated_unit_price ?? '0'), 4), 2);
            $rate = $this->exchangeRates->resolveRate($tenantId, (string) ($row->currency ?: $budgetCurrency), $budgetCurrency);
            $committed = Decimal::add($committed, Decimal::mul($amount, $rate, 2), 2);
        }

        // Encumbered Purchase Orders
        $poRows = DB::table('purchase_orders')
            ->where('tenant_id', $tenantId)
            ->where('cost_center_id', $costCenterId)
            ->where('is_budget_encumbered', true)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->whereNull('deleted_at')
            ->where('date', '>=', "{$fiscalYear}-01-01")
            ->where('date', '<=', "{$fiscalYear}-12-31")
            ->get(['currency', 'budget_committed_amount', 'total_amount']);

        foreach ($poRows as $po) {
            $poAmount = Decimal::cmp((string) ($po->budget_committed_amount ?? '0'), '0', 2) > 0
                ? Decimal::of((string) $po->budget_committed_amount, 2)
                : Decimal::of((string) ($po->total_amount ?? '0'), 2);
            $rate = $this->exchangeRates->resolveRate($tenantId, (string) ($po->currency ?: $budgetCurrency), $budgetCurrency);
            $committed = Decimal::add($committed, Decimal::mul($poAmount, $rate, 2), 2);
        }

        return $committed;
    }

    private function tenantDefaultCurrency(string $tenantId, string $fallback): string
    {
        $currency = Tenant::query()->whereKey($tenantId)->value('default_currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : $fallback;
    }
}
