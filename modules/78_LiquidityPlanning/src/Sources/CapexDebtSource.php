<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\Treasury\Models\InterestSchedule;
use Modules\Treasury\Models\LoanAgreement;

/**
 * The approved investment budget (capex: budget lines of the non-current asset accounts of an approved or locked budget) as
 * estimated outflows, and the debt service (principal and interest of the loan schedules not yet paid) as certain outflows.
 */
final class CapexDebtSource implements FlowSource
{
    public function flows(SourceContext $context): array
    {
        return [...$this->capex($context), ...$this->debtService($context)];
    }

    /**
     * @return list<Flow>
     */
    private function capex(SourceContext $context): array
    {
        if (! Schema::hasTable('budget_lines')) {
            return [];
        }

        $flows = [];
        $versions = BudgetVersion::query()->whereIn('status', ['approved', 'locked'])->where('version_type', 'budget')->where('currency', $context->currency)->get();

        foreach ($versions as $version) {
            $lines = DB::table('budget_lines')
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'budget_lines.account_id')
                ->where('budget_lines.version_id', $version->id)
                ->where('chart_of_accounts.reporting_class', 'non_current_asset')
                ->groupBy('budget_lines.period')
                ->selectRaw('budget_lines.period as period, SUM(budget_lines.amount) as amount')
                ->get();

            foreach ($lines as $line) {
                $date = CarbonImmutable::parse($version->year_start)->addMonthsNoOverflow(max(0, (int) $line->period - 1))->day(15);

                if ($date->betweenIncluded($context->start->startOfMonth(), $context->end) && Decimal::isPositive((string) $line->amount)) {
                    $flows[] = new Flow($context->onOrAfterStart($date), Flow::Outflow, Flow::Estimated, 'capex', 'Capex '.$version->fiscal_year, Decimal::round2((string) $line->amount), 'lpApi.category.capex');
                }
            }
        }

        return $flows;
    }

    /**
     * @return list<Flow>
     */
    private function debtService(SourceContext $context): array
    {
        $loans = LoanAgreement::query()->whereIn('status', ['active', 'defaulted'])->where('currency', $context->currency)->pluck('loan_reference', 'id');

        if ($loans->isEmpty()) {
            return [];
        }

        $flows = [];
        $schedules = InterestSchedule::query()
            ->whereIn('loan_agreement_id', $loans->keys()->all())
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<=', $context->end->toDateString())
            ->get();

        foreach ($schedules as $schedule) {
            $reference = (string) $loans->get($schedule->loan_agreement_id);
            $date = $context->onOrAfterStart($schedule->due_date);

            if (Decimal::isPositive((string) $schedule->principal_due)) {
                $flows[] = new Flow($date, Flow::Outflow, Flow::Certain, 'debt_principal', $reference, Decimal::round2((string) $schedule->principal_due), 'lpApi.category.debt_principal');
            }

            if (Decimal::isPositive((string) $schedule->interest_due)) {
                $flows[] = new Flow($date, Flow::Outflow, Flow::Certain, 'debt_interest', $reference, Decimal::round2((string) $schedule->interest_due), 'lpApi.category.debt_interest');
            }
        }

        return $flows;
    }
}
