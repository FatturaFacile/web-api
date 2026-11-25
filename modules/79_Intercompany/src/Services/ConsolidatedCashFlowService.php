<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Modules\GeneralLedger\Enums\ReportingClass;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\ConsolidationRunBalance;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\Intercompany\Support\GroupScope;

/**
 * Consolidated cash flow statement by the indirect method, from the consolidated balances of a run and of the opening run
 * (the last locked, else latest, run of the group that ends before the start of the fiscal year). The class of each account
 * is the reporting class of the chart of accounts of the entity that holds it (cash, receivable, inventory, current and
 * non-current items, equity, non cash expense; an account without a class counts as other operating).
 *
 *  - operating: result of the period, non cash expenses added back, change of receivables, inventories, other current
 *    assets and current liabilities (and of the accounts without a class);
 *  - investing: change of non-current assets plus the non cash expenses (depreciation), that is the net investment;
 *  - financing: change of equity (the opening result counts as opening equity, as the ledgers close it at year end) and of
 *    non-current liabilities, without the translation reserve;
 *  - effect of the translation: the movement of the translation reserve.
 * Without an opening run the opening balances are nil: the statement says so (`opening_available` false) and the first
 * consolidation shows the whole position as movement. A difference to the change of cash that the classification cannot
 * explain is shown as `unreconciled`, never hidden.
 */
final readonly class ConsolidatedCashFlowService
{
    public function __construct(
        private ConsolidationService $consolidation,
        private RunFigures $figures,
        private GroupScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cashFlow(User $user, ConsolidationRun $run): array
    {
        $this->consolidation->assertCanReadRun($user, $run);
        $closing = $this->figures->accounts($run);
        $opening = $this->openingRun($run);
        $openingAccounts = $opening === null ? [] : $this->figures->accounts($opening);
        $classes = $this->classes($run, $opening);
        $cta = (string) $this->scope->groupOfRun($run)->translation_reserve_code;

        $lines = ['receivable' => ConsolidationMath::zero(), 'inventory' => ConsolidationMath::zero(), 'other_current_asset' => ConsolidationMath::zero(), 'current_liability' => ConsolidationMath::zero(), 'unclassified' => ConsolidationMath::zero()];
        $closingCash = $openingCash = $noncash = $investing = $equityClosing = $equityOpening = $debt = $ctaDelta = ConsolidationMath::zero();
        $unclassified = [];

        foreach (array_unique([...array_keys($closing), ...array_keys($openingAccounts)]) as $code) {
            $account = $closing[$code] ?? $openingAccounts[$code];
            $type = $account['account_type'];
            $now = $closing[$code]['net'] ?? ConsolidationMath::zero();
            $before = $openingAccounts[$code]['net'] ?? ConsolidationMath::zero();
            $delta = ConsolidationMath::sub($now, $before);

            if ($code === $cta) {
                $ctaDelta = ConsolidationMath::add($ctaDelta, $delta);

                continue;
            }

            if (in_array($type, ['revenue', 'cogs', 'expense'], true)) {
                if (($classes[$code] ?? null) === ReportingClass::NonCashExpense) {
                    $noncash = ConsolidationMath::add($noncash, $now);
                }

                continue;
            }

            $class = $classes[$code] ?? null;

            if ($type === 'equity') {
                $equityClosing = ConsolidationMath::add($equityClosing, ConsolidationMath::neg($now));
                $equityOpening = ConsolidationMath::add($equityOpening, ConsolidationMath::neg($before));

                continue;
            }

            if ($class === ReportingClass::Cash) {
                $closingCash = ConsolidationMath::add($closingCash, $now);
                $openingCash = ConsolidationMath::add($openingCash, $before);
            } elseif ($class === ReportingClass::NonCurrentAsset) {
                $investing = ConsolidationMath::sub($investing, $delta);
            } elseif ($class === ReportingClass::NonCurrentLiability) {
                $debt = ConsolidationMath::sub($debt, $delta);
            } else {
                $key = match ($class) {
                    ReportingClass::Receivable => 'receivable',
                    ReportingClass::Inventory => 'inventory',
                    ReportingClass::OtherCurrentAsset => 'other_current_asset',
                    ReportingClass::CurrentLiability => 'current_liability',
                    default => 'unclassified',
                };

                if ($key === 'unclassified') {
                    $unclassified[] = $code;
                }

                $lines[$key] = ConsolidationMath::sub($lines[$key], $delta);
            }
        }

        $result = $this->result($closing);
        $openingResult = $this->result($openingAccounts);
        $investing = ConsolidationMath::sub($investing, $noncash);
        $financingEquity = ConsolidationMath::sub($equityClosing, ConsolidationMath::add($equityOpening, $openingResult));
        $operating = ConsolidationMath::add(ConsolidationMath::add($result, $noncash), array_reduce($lines, static fn (string $carry, string $amount): string => ConsolidationMath::add($carry, $amount), ConsolidationMath::zero()));
        $financing = ConsolidationMath::add($financingEquity, $debt);
        $fx = ConsolidationMath::neg($ctaDelta);
        $net = ConsolidationMath::add(ConsolidationMath::add($operating, $investing), ConsolidationMath::add($financing, $fx));
        $change = ConsolidationMath::sub($closingCash, $openingCash);

        return [
            'run_id' => (string) $run->id,
            'currency' => (string) $run->group_currency,
            'method' => 'indirect',
            'opening_run_id' => $opening === null ? null : (string) $opening->id,
            'opening_available' => $opening !== null,
            'operating' => $operating,
            'investing' => $investing,
            'financing' => $financing,
            'fx_effect' => $fx,
            'net_change' => $net,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'unreconciled' => ConsolidationMath::sub($change, $net),
            'lines' => [
                ['code' => 'result_of_the_period', 'section' => 'operating', 'amount' => $result],
                ['code' => 'non_cash_expenses', 'section' => 'operating', 'amount' => $noncash],
                ['code' => 'change_receivables', 'section' => 'operating', 'amount' => $lines['receivable']],
                ['code' => 'change_inventories', 'section' => 'operating', 'amount' => $lines['inventory']],
                ['code' => 'change_other_current_assets', 'section' => 'operating', 'amount' => $lines['other_current_asset']],
                ['code' => 'change_current_liabilities', 'section' => 'operating', 'amount' => $lines['current_liability']],
                ['code' => 'change_unclassified', 'section' => 'operating', 'amount' => $lines['unclassified']],
                ['code' => 'net_investment', 'section' => 'investing', 'amount' => $investing],
                ['code' => 'equity_movements', 'section' => 'financing', 'amount' => $financingEquity],
                ['code' => 'non_current_liabilities', 'section' => 'financing', 'amount' => $debt],
                ['code' => 'translation_effect', 'section' => 'fx', 'amount' => $fx],
            ],
            'unclassified_accounts' => array_values(array_unique($unclassified)),
        ];
    }

    /**
     * Result of the period: the opposite of the net of the income accounts.
     *
     * @param  array<string, array<string, string>>  $accounts
     */
    private function result(array $accounts): string
    {
        $net = ConsolidationMath::zero();

        foreach ($accounts as $account) {
            if (in_array($account['account_type'], ['revenue', 'cogs', 'expense'], true)) {
                $net = ConsolidationMath::add($net, $account['net']);
            }
        }

        return ConsolidationMath::neg($net);
    }

    private function openingRun(ConsolidationRun $run): ?ConsolidationRun
    {
        $candidates = ConsolidationRun::withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->where('group_id', $run->group_id)
            ->whereDate('period_end', '<', $run->year_start->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('version')
            ->get();

        return $candidates->firstWhere('status', ConsolidationRun::StatusLocked) ?? $candidates->first();
    }

    /**
     * @return array<string, ReportingClass|null>
     */
    private function classes(ConsolidationRun $run, ?ConsolidationRun $opening): array
    {
        $classes = [];

        foreach (array_filter([$run, $opening]) as $source) {
            $rows = ConsolidationRunBalance::withoutGlobalScopes()->where('tenant_id', $source->tenant_id)->where('run_id', $source->id)->whereNotNull('account_id')->get(['account_code', 'account_id', 'member_tenant_id']);

            foreach ($rows as $row) {
                $code = (string) $row->account_code;

                if (isset($classes[$code])) {
                    continue;
                }

                $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $row->member_tenant_id)->find($row->account_id);
                $classes[$code] = $account === null ? null : ReportingClass::of($account)[0];
            }
        }

        return $classes;
    }
}
