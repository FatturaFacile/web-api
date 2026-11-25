<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Illuminate\Support\Facades\DB;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Domain\Strategy\ProfitAllocationInput;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\DepositPackage;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Generation of the figures of a dossier: the reclassified statements with the rounding quadrature, the cash flow statement, the
 * profit allocation proposal, the hidden reserves view and the crisis indicators.
 */
final class StatutoryGenerationService
{
    public function __construct(
        private readonly StatutoryBalanceBuilder $balances,
        private readonly CashFlowStatementBuilder $cashFlows,
        private readonly StatementPersistence $persistence,
        private readonly FinancialSnapshotBuilder $snapshots,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly HiddenReservesService $hiddenReserves,
        private readonly DossierService $dossiers,
    ) {}

    public function generateStatements(StatutoryDossier $dossier): StatutoryDossier
    {
        $this->dossiers->assertEditable($dossier);

        $bundle = $this->balances->build($dossier);
        $strategy = $this->strategies->for($dossier->accounting_standard);
        $metrics = $this->snapshots->entityMetrics($dossier, $bundle);
        $eligibility = $strategy->evaluateEligibility($metrics, $dossier->accounting_standard);
        $cashFlow = $strategy->cashFlowRequired($dossier->accounting_standard, $metrics) || $dossier->cash_flow_statement !== null
            ? $this->cashFlows->build($dossier, $bundle, $this->cashFlowMethod($dossier))
            : null;

        DB::transaction(function () use ($dossier, $bundle, $eligibility, $metrics, $cashFlow): void {
            $dossier->eligibility = array_merge($eligibility->toArray(), ['metrics' => $metrics->toArray()]);
            $this->persistence->persist($dossier, $bundle);

            if ($cashFlow !== null) {
                $this->persistence->persistCashFlow($dossier, $bundle, $cashFlow);
            }

            $this->refreshAllocation($dossier, $bundle);
            $dossier->status = DossierStatus::Reclassified;
            $dossier->generated_at = now();
            $dossier->validated_at = null;
            $dossier->save();

            DepositPackage::query()->where('dossier_id', $dossier->id)->update(['validation_status' => 'not_run']);
        });

        return $dossier->refresh();
    }

    public function generateCashFlow(StatutoryDossier $dossier, ?CashFlowMethod $method = null): StatutoryDossier
    {
        $this->dossiers->assertEditable($dossier);

        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $bundle = $this->persistence->rehydrate($dossier);

        if ($method !== null) {
            $dossier->cash_flow_method = $method;
        }

        $cashFlow = $this->cashFlows->build($dossier, $bundle, $this->cashFlowMethod($dossier));
        $this->persistence->persistCashFlow($dossier, $bundle, $cashFlow);
        $dossier->validated_at = null;
        $dossier->save();

        return $dossier->refresh();
    }

    /**
     * Profit allocation proposal and, for Switzerland, the hidden reserves view, from the generated statements.
     */
    public function refreshAllocation(StatutoryDossier $dossier, StatementBundle $bundle): void
    {
        $family = $bundle->standard->jurisdiction();
        $strategy = $this->strategies->for($bundle->standard);
        $totals = $bundle->quadrature->balanceSheetTotals;
        $inputs = $dossier->profit_allocation_proposal ?? [];
        $profit = $bundle->quadrature->incomeStatementTotals[$bundle->codes['is_profit']] ?? '0';

        [$capitalCode, $legalCodes, $carriedCode] = $family === 'IT'
            ? ['it.bs.pn.i', ['it.bs.pn.iv'], 'it.bs.pn.viii']
            : ['ch.bs.share_capital', ['ch.bs.legal_profit_res'], 'ch.bs.retained'];

        $shareCapital = $dossier->share_capital !== null ? Money::from($dossier->share_capital) : Money::from($totals[$capitalCode] ?? '0');
        $legal = Money::sum(array_map(static fn (string $code): string => $totals[$code] ?? '0', $legalCodes));
        $carried = array_key_exists('input_carried_forward', $inputs) ? Money::from($inputs['input_carried_forward']) : Money::from($totals[$carriedCode] ?? '0');

        $proposal = $strategy->profitAllocation(new ProfitAllocationInput(
            netProfit: $profit,
            carriedForward: $carried,
            shareCapital: $shareCapital,
            legalReserve: $legal,
            dividends: (string) ($inputs['input_dividends'] ?? '0'),
            otherReserves: (string) ($inputs['input_other_reserves'] ?? '0'),
        ));

        $dossier->profit_allocation_proposal = array_merge(array_intersect_key($inputs, array_flip(['input_dividends', 'input_other_reserves', 'input_carried_forward'])), $proposal);

        if ($family === 'CH' && is_array($dossier->hidden_reserves_config)) {
            $config = $dossier->hidden_reserves_config;
            $config['result'] = $this->hiddenReserves->evaluate($bundle->standard, $config, $totals['ch.bs.equity'] ?? '0', $profit);
            $dossier->hidden_reserves_config = $config;
        }
    }

    /**
     * Ratios, crisis indicators and the DSCR of a generated dossier, with optional what-if parameters.
     *
     * @param  array<string, string>  $whatIf
     * @return array<string, mixed>
     */
    public function indicators(StatutoryDossier $dossier, array $whatIf = []): array
    {
        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $bundle = $this->persistence->rehydrate($dossier);
        $snapshot = $this->snapshots->build($dossier, $bundle);

        return $this->strategies->for($dossier->accounting_standard)->indicators($snapshot, $whatIf);
    }

    private function cashFlowMethod(StatutoryDossier $dossier): CashFlowMethod
    {
        $methods = $this->strategies->for($dossier->accounting_standard)->cashFlowMethods($dossier->accounting_standard);

        return in_array($dossier->cash_flow_method, $methods, true) ? $dossier->cash_flow_method : CashFlowMethod::Indirect;
    }
}
