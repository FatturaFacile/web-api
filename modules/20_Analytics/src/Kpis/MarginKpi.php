<?php

declare(strict_types=1);

namespace Modules\Analytics\Kpis;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Modules\Analytics\Contracts\KpiCalculator;
use Modules\Analytics\Support\ReportPeriod;
use Modules\GeneralLedger\Services\LedgerBalanceReader;

/**
 * Gross margin of the period (revenue minus cost of goods sold) read from the statutory ledger, which only holds posted
 * (or reversed, then cancelled out) journal entries. When no revenue or cost of goods sold account has movements in the
 * period the value is null, not zero.
 */
final readonly class MarginKpi implements KpiCalculator
{
    private const int Scale = 4;

    /**
     * Inject the collaborators.
     */
    public function __construct(private LedgerBalanceReader $ledger, private TenantContext $tenantContext) {}

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'margin';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return 'Gross margin';
    }

    /**
     * {@inheritDoc}
     */
    public function unit(): string
    {
        return 'currency';
    }

    /**
     * {@inheritDoc}
     */
    public function kind(): string
    {
        return 'period';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredModule(): string
    {
        return 'ledger';
    }

    /**
     * {@inheritDoc}
     */
    public function calculate(ReportPeriod $period): array
    {
        $accounts = $this->accounts($period);

        if ($accounts->isEmpty()) {
            return ['value' => null, 'details' => ['revenue' => null, 'cost_of_goods_sold' => null, 'margin_percent' => null]];
        }

        $revenue = $this->total($accounts, 'revenue');
        $cogs = $this->total($accounts, 'cogs');
        $margin = bcsub($revenue, $cogs, self::Scale);

        return [
            'value' => bcadd($margin, '0', 2),
            'details' => [
                'revenue' => bcadd($revenue, '0', 2),
                'cost_of_goods_sold' => bcadd($cogs, '0', 2),
                'margin_percent' => bccomp($revenue, '0', self::Scale) === 0 ? null : bcround(bcdiv(bcmul($margin, '100', self::Scale), $revenue, self::Scale), 2),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rows(ReportPeriod $period, int $limit): array
    {
        return $this->accounts($period)
            ->take($limit)
            ->map(fn (object $account): array => [
                'account_code' => (string) $account->account_code,
                'account' => (string) $account->account_name,
                'type' => (string) $account->account_type,
                'amount' => bcadd($this->ledger->naturalAmount($account), '0', 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Revenue and cost of goods sold accounts with movements in the period.
     *
     * @return Collection<string, object>
     */
    private function accounts(ReportPeriod $period): Collection
    {
        return $this->ledger->movements((string) $this->tenantContext->getTenantId(), $period->from, $period->to)
            ->filter(static fn (object $account): bool => in_array($account->account_type, ['revenue', 'cogs'], true));
    }

    /**
     * @param  Collection<string, object>  $accounts
     */
    private function total(Collection $accounts, string $type): string
    {
        return $accounts
            ->where('account_type', $type)
            ->reduce(fn (string $sum, object $account): string => bcadd($sum, $this->ledger->naturalAmount($account), self::Scale), '0');
    }
}
