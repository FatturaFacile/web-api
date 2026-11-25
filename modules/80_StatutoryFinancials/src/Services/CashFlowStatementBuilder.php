<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Rounding\RoundingQuadrature;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Support\Money;

/**
 * The cash flow statement (OIC 10 for Italy, Swiss GAAP FER 4 for Switzerland).
 *
 * Indirect method: starts from the result of the year, adds back the depreciation and takes every other movement from the change
 * of the balance sheet lines between the start and the end of the year (their cash flow group is part of the seeded catalogue), so
 * the statement reconciles to the movement of cash by construction. Direct method: the cash accounts' entries are analysed by
 * their counterpart accounts (receipts from customers, payments to suppliers, investing and financing flows).
 */
final class CashFlowStatementBuilder
{
    public function __construct(private readonly LineCatalogRepository $catalogs, private readonly AccountClassifier $classifier) {}

    /**
     * @return array<string, mixed>
     */
    public function build(StatutoryDossier $dossier, StatementBundle $bundle, CashFlowMethod $method): array
    {
        $standard = $bundle->standard;
        $balance = $bundle->balanceSheet->lines();
        $cashLeaves = array_keys(array_filter($balance, static fn ($line): bool => $line->cfGroup === 'cash' && ! in_array($line->side, ['subtotal'], true)));
        $cashLeaves = array_values(array_filter($cashLeaves, fn (string $code): bool => $bundle->balanceSheet->isLeaf($code)));

        $openingCash = Money::sum(array_map(static fn (string $code): string => $bundle->openingBalanceSheetExact[$code] ?? '0', $cashLeaves));
        $closingCashExact = Money::sum(array_map(static fn (string $code): string => $bundle->balanceSheetExact[$code] ?? '0', $cashLeaves));
        $closingCashRounded = Money::sum(array_map(static fn (string $code): string => $bundle->quadrature->balanceSheetLeaves[$code] ?? '0', $cashLeaves));

        $leaves = $method === CashFlowMethod::Direct
            ? $this->direct($dossier, $bundle, $cashLeaves)
            : $this->indirect($bundle, $cashLeaves);

        $explained = Money::sum(array_filter($leaves, static fn (string $code): bool => str_starts_with($code, 'cf.a.') || str_starts_with($code, 'cf.b.') || str_starts_with($code, 'cf.c.'), ARRAY_FILTER_USE_KEY));
        $unexplained = Money::sub(Money::sub($closingCashExact, $openingCash), $explained);

        if (! Money::isZero($unexplained)) {
            $other = $method === CashFlowMethod::Direct ? 'cf.a.payments' : 'cf.a.other';
            $leaves[$other] = Money::add($leaves[$other] ?? '0', $unexplained);
        }

        $catalog = $this->catalogs->catalog($standard, 'cash_flow', $method->value);
        $rounded = (new RoundingQuadrature($bundle->scale))->applyCashFlow($catalog, $leaves, $openingCash, $closingCashRounded, [
            'opening' => 'cf.open', 'closing' => 'cf.close', 'net_total' => 'cf.net', 'rounding' => 'cf.round', 'opening_line' => 'cf.open',
        ]);

        return [
            'method' => $method->value,
            'exact' => $leaves,
            'leaves' => $rounded['leaves'],
            'totals' => $rounded['totals'],
            'residual' => $rounded['residual'],
            'opening_cash' => $rounded['totals']['cf.open'] ?? '0',
            'closing_cash' => $rounded['totals']['cf.close'] ?? '0',
            'balance_sheet_cash' => Money::round($closingCashRounded, $bundle->scale),
            'unexplained' => Money::round($unexplained),
            'reconciled' => Money::compare($rounded['totals']['cf.close'] ?? '0', $closingCashRounded) === 0,
        ];
    }

    /**
     * @param  list<string>  $cashLeaves
     * @return array<string, string>
     */
    private function indirect(StatementBundle $bundle, array $cashLeaves): array
    {
        $lines = $bundle->balanceSheet->lines();
        $effects = ['cf.a.inv' => '0', 'cf.a.rec' => '0', 'cf.a.pay' => '0', 'cf.a.other' => '0', 'cf.a.prov' => '0', 'cf.b.fin' => '0', 'cf.c.debt' => '0', 'cf.c.equity' => '0'];
        $tangible = '0';
        $intangible = '0';
        $tangibleClosing = '0';
        $intangibleClosing = '0';

        foreach ($bundle->balanceSheet->leaves() as $code) {
            $line = $lines[$code];
            $group = $line->cfGroup ?? 'wc_other';

            if (in_array($code, $cashLeaves, true) || in_array($group, ['cash', 'profit', 'rounding'], true)) {
                continue;
            }

            $delta = Money::sub($bundle->balanceSheetExact[$code] ?? '0', $bundle->openingBalanceSheetExact[$code] ?? '0');
            $effect = $line->side === 'asset' ? Money::neg($delta) : $delta;

            switch ($group) {
                case 'wc_inventory':
                    $effects['cf.a.inv'] = Money::add($effects['cf.a.inv'], $effect);
                    break;
                case 'wc_receivables':
                    $effects['cf.a.rec'] = Money::add($effects['cf.a.rec'], $effect);
                    break;
                case 'wc_payables':
                    $effects['cf.a.pay'] = Money::add($effects['cf.a.pay'], $effect);
                    break;
                case 'provisions':
                    $effects['cf.a.prov'] = Money::add($effects['cf.a.prov'], $effect);
                    break;
                case 'invest_tangible':
                    $tangible = Money::add($tangible, $effect);
                    $tangibleClosing = Money::add($tangibleClosing, $bundle->balanceSheetExact[$code] ?? '0');
                    break;
                case 'invest_intangible':
                    $intangible = Money::add($intangible, $effect);
                    $intangibleClosing = Money::add($intangibleClosing, $bundle->balanceSheetExact[$code] ?? '0');
                    break;
                case 'invest_financial':
                    $effects['cf.b.fin'] = Money::add($effects['cf.b.fin'], $effect);
                    break;
                case 'fin_debt':
                    $effects['cf.c.debt'] = Money::add($effects['cf.c.debt'], $effect);
                    break;
                case 'fin_equity':
                    $effects['cf.c.equity'] = Money::add($effects['cf.c.equity'], $effect);
                    break;
                default:
                    $effects['cf.a.other'] = Money::add($effects['cf.a.other'], $effect);
            }
        }

        $depreciation = $this->depreciation($bundle);
        $fixedClosing = Money::add($tangibleClosing, $intangibleClosing);
        $intangibleShare = Money::isPositive($fixedClosing) ? Money::mul($depreciation, Money::div($intangibleClosing, $fixedClosing) ?? '0') : '0';
        $tangibleShare = Money::sub($depreciation, $intangibleShare);
        $profit = $bundle->incomeStatement->totals($bundle->incomeStatementExact)[$bundle->codes['is_profit']] ?? '0';

        return [
            'cf.a.profit' => $profit,
            'cf.a.dep' => $depreciation,
            'cf.a.prov' => $effects['cf.a.prov'],
            'cf.a.inv' => $effects['cf.a.inv'],
            'cf.a.rec' => $effects['cf.a.rec'],
            'cf.a.pay' => $effects['cf.a.pay'],
            'cf.a.other' => $effects['cf.a.other'],
            'cf.b.tang' => Money::sub($tangible, $tangibleShare),
            'cf.b.intang' => Money::sub($intangible, $intangibleShare),
            'cf.b.fin' => $effects['cf.b.fin'],
            'cf.c.debt' => $effects['cf.c.debt'],
            'cf.c.equity' => Money::sub($effects['cf.c.equity'], $bundle->openingProfit),
        ];
    }

    private function depreciation(StatementBundle $bundle): string
    {
        $family = $bundle->standard->jurisdiction();
        $definitions = $this->catalogs->definitions($family, 'income_statement', 'total_cost');
        $sum = '0';

        foreach ($bundle->classification->incomeLeaves as $code => $amount) {
            if (($definitions[$code]->cfGroup ?? null) === 'noncash') {
                $sum = Money::add($sum, $amount);
            }
        }

        return $sum;
    }

    /**
     * @param  list<string>  $cashLeaves
     * @return array<string, string>
     */
    private function direct(StatutoryDossier $dossier, StatementBundle $bundle, array $cashLeaves): array
    {
        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $cashCodes = [];

        foreach ($cashLeaves as $leaf) {
            foreach ($bundle->classification->sources[$leaf] ?? [] as $source) {
                $cashCodes[$source['account_code']] = true;
            }
        }

        $tenantId = (string) $dossier->tenant_id;
        $base = static fn () => DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $tenantId)
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->where('journal_entries.ledger_type', 'statutory')
            ->whereDate('journal_entries.posting_date', '>=', $year->start_date->toDateString())
            ->whereDate('journal_entries.posting_date', '<=', $year->end_date->toDateString())
            ->whereNotIn('journal_entries.journal_type', ['opening', 'closing'])
            ->where(static fn ($query) => $query->whereNull('journal_entries.source_document_type')->orWhereNotIn('journal_entries.source_document_type', ['opening_balance', 'year_end_closing']));

        $leaves = ['cf.a.receipts' => '0', 'cf.a.payments' => '0', 'cf.b.tang' => '0', 'cf.b.intang' => '0', 'cf.b.fin' => '0', 'cf.c.debt' => '0', 'cf.c.equity' => '0'];

        if ($cashCodes === []) {
            return $leaves;
        }

        $entryIds = $base()->whereIn('chart_of_accounts.account_code', array_keys($cashCodes))->distinct()->pluck('journal_entries.id')->all();

        foreach (array_chunk($entryIds, 400) as $chunk) {
            $rows = $base()
                ->whereIn('journal_entries.id', $chunk)
                ->whereNotIn('chart_of_accounts.account_code', array_keys($cashCodes))
                ->groupBy('chart_of_accounts.id', 'chart_of_accounts.account_code', 'chart_of_accounts.account_type', 'chart_of_accounts.reporting_class', 'chart_of_accounts.reconciliation_type', 'chart_of_accounts.statutory_code')
                ->selectRaw('chart_of_accounts.id as account_id, chart_of_accounts.account_code, chart_of_accounts.account_type, chart_of_accounts.reporting_class, chart_of_accounts.reconciliation_type, chart_of_accounts.statutory_code, SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)) - SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)) as effect')
                ->get();

            foreach ($rows as $row) {
                $effect = Money::from($row->effect);
                $group = $this->classifier->groupOf($bundle->standard, $row, $row->statutory_code);
                $target = match (true) {
                    $group === 'invest_tangible' => 'cf.b.tang',
                    $group === 'invest_intangible' => 'cf.b.intang',
                    $group === 'invest_financial' => 'cf.b.fin',
                    $group === 'fin_debt' => 'cf.c.debt',
                    $group === 'fin_equity' => 'cf.c.equity',
                    $group === 'wc_receivables', $row->account_type === 'revenue' => 'cf.a.receipts',
                    default => 'cf.a.payments',
                };
                $leaves[$target] = Money::add($leaves[$target], $effect);
            }
        }

        return $leaves;
    }
}
