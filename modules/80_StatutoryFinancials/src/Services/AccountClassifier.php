<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\LedgerBalanceReader;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Statement\ClassificationResult;
use Modules\StatutoryFinancials\Domain\Statement\LineDefinition;
use Modules\StatutoryFinancials\Models\AccountMapping;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Reclassifies the chart of accounts into the lines of the official statements.
 *
 * Resolution order of an account: (1) its statutory code, when it names a line ("a" or "a|b" for a variable-sign account);
 * (2) the longest matching code prefix of a mapping rule, a company rule before a global one; (3) its nature (reporting class and
 * account type), which is reported as an unmapped account.
 *
 * Bifronte accounts (banks, VAT and tax accounts, receivables and payables that change sign at year-end) carry a line for the
 * normal side and a line for the opposite one: a debit balance goes to the asset side, a credit balance to the liability side,
 * always as a positive amount of the line it lands on, so the sign is never corrupted.
 */
final class AccountClassifier
{
    /**
     * Fallback by reporting class: [natural line, opposite line] for Italy and Switzerland.
     *
     * @var array<string, array<string, array{0: string, 1: ?string}>>
     */
    private const array ClassFallback = [
        'IT' => [
            'cash' => ['it.bs.c.iv.1', 'it.bs.deb.4'],
            'receivable' => ['it.bs.c.ii.1', 'it.bs.deb.6'],
            'inventory' => ['it.bs.c.i', null],
            'other_current_asset' => ['it.bs.c.ii.5quater', 'it.bs.deb.14'],
            'non_current_asset' => ['it.bs.b.ii.4', null],
            'current_liability' => ['it.bs.deb.14', 'it.bs.c.ii.5quater'],
            'non_current_liability' => ['it.bs.deb.4', null],
            'equity' => ['it.bs.pn.vi', null],
        ],
        'CH' => [
            'cash' => ['ch.bs.cash', 'ch.bs.st_debt'],
            'receivable' => ['ch.bs.trade_rec', 'ch.bs.other_st_pay'],
            'inventory' => ['ch.bs.inventory', null],
            'other_current_asset' => ['ch.bs.other_rec', 'ch.bs.other_st_pay'],
            'non_current_asset' => ['ch.bs.tangible', null],
            'current_liability' => ['ch.bs.other_st_pay', 'ch.bs.other_rec'],
            'non_current_liability' => ['ch.bs.lt_debt', null],
            'equity' => ['ch.bs.vol_res', null],
        ],
    ];

    /**
     * Fallback by account type when the reporting class is missing.
     *
     * @var array<string, array<string, array{0: string, 1: ?string}>>
     */
    private const array TypeFallback = [
        'IT' => [
            'asset' => ['it.bs.c.ii.5quater', 'it.bs.deb.14'], 'wip' => ['it.bs.c.i', null], 'liability' => ['it.bs.deb.14', 'it.bs.c.ii.5quater'], 'equity' => ['it.bs.pn.vi', null],
            'revenue' => ['it.is.a.5', null], 'cogs' => ['it.is.b.6', null], 'expense' => ['it.is.b.14', null],
        ],
        'CH' => [
            'asset' => ['ch.bs.other_rec', 'ch.bs.other_st_pay'], 'wip' => ['ch.bs.inventory', null], 'liability' => ['ch.bs.other_st_pay', 'ch.bs.other_rec'], 'equity' => ['ch.bs.vol_res', null],
            'revenue' => ['ch.is.tc.rev', null], 'cogs' => ['ch.is.tc.material', null], 'expense' => ['ch.is.tc.other_opex', null],
        ],
    ];

    /** @var array<string, list<AccountMapping>> */
    private array $mappings = [];

    public function __construct(private readonly LineCatalogRepository $catalogs) {}

    /**
     * @param  iterable<object>  $balanceRows  Rows of LedgerBalanceReader::cumulative() (balance sheet accounts).
     * @param  iterable<object>  $incomeRows  Rows of LedgerBalanceReader::movements() (income accounts are taken from here).
     */
    public function classify(AccountingStandard $standard, iterable $balanceRows, iterable $incomeRows): ClassificationResult
    {
        $family = $standard->jurisdiction();
        $bsDefs = $this->catalogs->definitions($family, 'balance_sheet');
        $isDefs = $this->catalogs->definitions($family, 'income_statement', 'total_cost');
        $bsCatalog = $this->catalogs->catalog($standard, 'balance_sheet');
        $isCatalog = $this->catalogs->catalog($standard, 'income_statement', 'total_cost');

        $rows = [];

        foreach ($balanceRows as $row) {
            if (in_array($row->account_type, LedgerBalanceReader::BalanceSheetTypes, true)) {
                $rows[] = $row;
            }
        }

        foreach ($incomeRows as $row) {
            if (in_array($row->account_type, LedgerBalanceReader::IncomeTypes, true)) {
                $rows[] = $row;
            }
        }

        $statutory = $this->statutoryCodes($rows);
        $balanceLeaves = [];
        $incomeLeaves = [];
        $sources = [];
        $bifront = [];
        $unmapped = [];

        foreach ($rows as $row) {
            $balance = Money::sub((string) $row->debit, (string) $row->credit);

            if (Money::isZero($balance)) {
                continue;
            }

            [$line, $alternative, $isFallback] = $this->rule($family, $row, $statutory[$row->account_id] ?? null, $bsDefs + $isDefs);
            $isIncome = in_array($row->account_type, LedgerBalanceReader::IncomeTypes, true);
            $defs = $isIncome ? $isDefs : $bsDefs;
            $catalog = $isIncome ? $isCatalog : $bsCatalog;
            $target = $line;
            $amount = $this->natural($defs[$line] ?? null, $balance);

            if ($alternative !== null && Money::isNegative($amount)) {
                $target = $alternative;
                $amount = $this->natural($bsDefs[$alternative] ?? null, $balance);
            }

            if ($alternative !== null) {
                $bifront[] = [
                    'account_code' => (string) $row->account_code,
                    'account_name' => (string) $row->account_name,
                    'balance' => $balance,
                    'line' => $target,
                    'side' => $target === $line ? 'normal' : 'opposite',
                ];
            }

            $leaf = $catalog->resolveLeaf($target, $defs) ?? $catalog->resolveLeaf($target, $bsDefs + $isDefs);

            if ($leaf === null) {
                continue;
            }

            if ($isFallback) {
                $unmapped[] = ['account_code' => (string) $row->account_code, 'account_name' => (string) $row->account_name, 'line' => $leaf];
            }

            if ($isIncome) {
                $incomeLeaves[$leaf] = Money::add($incomeLeaves[$leaf] ?? '0', $amount);
            } else {
                $balanceLeaves[$leaf] = Money::add($balanceLeaves[$leaf] ?? '0', $amount);
            }

            $sources[$leaf][] = ['account_code' => (string) $row->account_code, 'account_name' => (string) $row->account_name, 'amount' => $amount, 'line' => $target];
        }

        return new ClassificationResult($balanceLeaves, $incomeLeaves, $sources, $bifront, $unmapped);
    }

    /**
     * The statement line group (cash flow group) of an account, used by the direct cash flow method.
     */
    public function groupOf(AccountingStandard $standard, object $row, ?string $statutoryCode = null): ?string
    {
        $family = $standard->jurisdiction();
        $defs = $this->catalogs->definitions($family, 'balance_sheet') + $this->catalogs->definitions($family, 'income_statement', 'total_cost');
        [$line] = $this->rule($family, $row, $statutoryCode, $defs);

        return $defs[$line]->cfGroup ?? ($defs[$line]->side === 'revenue' ? 'revenue' : null);
    }

    /**
     * Amount of an account balance (debit minus credit) on the natural side of a line: assets and costs are debit, the rest credit.
     */
    private function natural(?LineDefinition $line, string $balance): string
    {
        if ($line === null) {
            return $balance;
        }

        return in_array($line->side, ['asset', 'cost'], true) ? $balance : Money::neg($balance);
    }

    /**
     * @param  array<string, LineDefinition>  $defs
     * @return array{0: string, 1: ?string, 2: bool} [line, opposite line, true when only the nature of the account decided]
     */
    private function rule(string $family, object $row, ?string $statutoryCode, array $defs): array
    {
        if ($statutoryCode !== null && $statutoryCode !== '') {
            [$line, $alternative] = array_pad(explode('|', $statutoryCode, 2), 2, null);

            if (isset($defs[$line])) {
                return [$line, $alternative !== null && isset($defs[$alternative]) ? $alternative : null, false];
            }
        }

        $code = (string) $row->account_code;

        foreach ($this->mappingsFor($family) as $mapping) {
            if (str_starts_with($code, $mapping->code_prefix) && isset($defs[$mapping->line_code])) {
                $alternative = $mapping->alt_line_code;

                // Receivable, payable and bank accounts change side at year-end even when the rule names one line only.
                if ($alternative === null && in_array($row->reconciliation_type ?? null, ['customer', 'supplier', 'bank'], true)) {
                    $alternative = self::ClassFallback[$family][$row->reporting_class ?? ''][1] ?? null;
                }

                return [$mapping->line_code, $alternative !== null && isset($defs[$alternative]) ? $alternative : null, false];
            }
        }

        $fallback = self::ClassFallback[$family][(string) ($row->reporting_class ?? '')] ?? null;

        if ($fallback === null || ! in_array($row->account_type, LedgerBalanceReader::BalanceSheetTypes, true)) {
            $fallback = self::TypeFallback[$family][(string) $row->account_type] ?? self::TypeFallback[$family]['liability'];
        }

        return [$fallback[0], $fallback[1], true];
    }

    /**
     * @return list<AccountMapping>
     */
    private function mappingsFor(string $family): array
    {
        if (isset($this->mappings[$family])) {
            return $this->mappings[$family];
        }

        $rules = AccountMapping::query()->where('family', $family)->get()->all();

        // Longest prefix first; at the same length a company rule beats the global one, then the higher priority.
        usort($rules, static fn (AccountMapping $a, AccountMapping $b): int => [strlen($b->code_prefix), $b->tenant_id !== null ? 1 : 0, $b->priority] <=> [strlen($a->code_prefix), $a->tenant_id !== null ? 1 : 0, $a->priority]);

        return $this->mappings[$family] = $rules;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, ?string>
     */
    private function statutoryCodes(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (object $row): string => (string) $row->account_id, $rows)));

        if ($ids === []) {
            return [];
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $codes = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $query = DB::table('chart_of_accounts')->whereIn('id', $chunk)->whereNotNull('statutory_code');

            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            foreach ($query->pluck('statutory_code', 'id') as $id => $code) {
                $codes[(string) $id] = (string) $code;
            }
        }

        return $codes;
    }
}
