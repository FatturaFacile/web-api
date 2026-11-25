<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GdoTradeTerms\Contracts\GdoLedgerPosterInterface;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\LineStatus;
use Modules\GdoTradeTerms\Domain\Enums\PromotionStatus;
use Modules\GdoTradeTerms\Domain\Enums\RunStatus;
use Modules\GdoTradeTerms\Domain\Enums\SettlementLineStatus;
use Modules\GdoTradeTerms\Domain\Enums\SourceType;
use Modules\GdoTradeTerms\Events\AccrualRunPosted;
use Modules\GdoTradeTerms\Exceptions\GdoTradeTermsException;
use Modules\GdoTradeTerms\Models\GdoAccrualLine;
use Modules\GdoTradeTerms\Models\GdoAccrualRun;
use Modules\GdoTradeTerms\Models\GdoContract;
use Modules\GdoTradeTerms\Models\GdoSettlementLine;
use Modules\GdoTradeTerms\Support\Money;

/**
 * Monthly closing of the accrued rebates and contributions (stanziamento ratei mensili). Every run computes, per contract and
 * per rebate scale or promotion, what has been earned from the start up to the end of the month, and accrues only the
 * difference from what is already covered, so a month can be re-run safely: a posted month is returned unchanged, a recalculation
 * reverses it first, and only the latest month can be reversed or recalculated. The entry is Dr rebates expense / Cr provision
 * for credit notes to be issued (the opposite when a return or a credit note lowers the earned amount).
 */
final class AccrualService
{
    public function __construct(
        private readonly EarningsService $earnings,
        private readonly GdoLedgerPosterInterface $ledger,
        private readonly LedgerAccountResolver $accounts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $tenantId, Carbon $periodEnd, bool $recalculate, bool $dryRun, ?string $userId): array
    {
        $start = $periodEnd->copy()->startOfMonth()->startOfDay();
        $end = $periodEnd->copy()->endOfMonth()->startOfDay();
        $key = $start->format('Y-m');

        if ($start->gt(now()->startOfMonth())) {
            throw new GdoTradeTermsException('period_in_future');
        }

        if ($dryRun) {
            return $this->compute($tenantId, $key, $start, $end);
        }

        return DB::transaction(function () use ($tenantId, $key, $start, $end, $recalculate, $userId): array {
            $live = GdoAccrualRun::query()->where('status', RunStatus::Posted->value)->orderByDesc('period_key')->lockForUpdate()->get();
            $existing = $live->firstWhere('period_key', $key);

            if ($existing !== null && ! $recalculate) {
                return ['status' => 'already_posted', 'run' => $existing->load('lines'), 'lines' => [], 'total_accrued' => $existing->total_accrued, 'contracts_count' => $existing->contracts_count, 'period_key' => $key, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()];
            }

            $latest = $live->first();

            if ($latest !== null && $latest->period_key > $key) {
                throw new GdoTradeTermsException($existing === null ? 'period_before_latest_run' : 'later_runs_exist', 409, ['period' => (string) $latest->period_key]);
            }

            if ($existing !== null) {
                $this->reverse($existing, 'recalculation', $userId);
            }

            $result = $this->compute($tenantId, $key, $start, $end);

            return $this->post($tenantId, $result, $userId);
        });
    }

    public function reverse(GdoAccrualRun $run, string $reason, ?string $userId): GdoAccrualRun
    {
        return DB::transaction(function () use ($run, $reason, $userId): GdoAccrualRun {
            $locked = GdoAccrualRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RunStatus::Posted) {
                throw new GdoTradeTermsException('run_not_posted', 409);
            }

            if (GdoAccrualRun::query()->where('status', RunStatus::Posted->value)->where('period_key', '>', $locked->period_key)->exists()) {
                throw new GdoTradeTermsException('later_runs_exist', 409);
            }

            if (GdoAccrualLine::query()->where('run_id', $locked->id)->where('status', LineStatus::Settled->value)->exists()) {
                throw new GdoTradeTermsException('run_has_settled_lines', 409);
            }

            $reversal = $locked->journal_entry_id === null ? null : $this->ledger->reverse($locked->journal_entry_id, now()->toDateString(), $reason, $userId);

            GdoAccrualLine::query()->where('run_id', $locked->id)->update(['status' => LineStatus::Reversed->value]);
            $locked->update([
                'status' => RunStatus::Reversed->value,
                'unique_key' => null,
                'reversal_journal_entry_id' => $reversal,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'updated_by' => $userId,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * What is already covered for a rebate scale or promotion: the accruals that stand plus the variance the settlements
     * recognised when they settled it, so that a settled amount is never accrued again.
     */
    public function covered(SourceType $type, string $sourceId): string
    {
        $accrued = GdoAccrualLine::query()->where('source_type', $type->value)->where('source_id', $sourceId)->where('status', '!=', LineStatus::Reversed->value)->sum('delta_amount');
        $variance = GdoSettlementLine::query()->where('source_type', $type->value)->where('source_id', $sourceId)->where('status', SettlementLineStatus::Settled->value)->sum('variance_amount');

        return Money::add(Money::from($accrued), Money::from($variance));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $tenantId, string $key, Carbon $start, Carbon $end): array
    {
        $lines = [];

        $contracts = GdoContract::query()
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Closed->value])
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->with(['scales.tiers', 'promotions'])
            ->orderBy('code')
            ->get();

        foreach ($contracts as $contract) {
            if ($contract->ends_on->lt($start) && ! GdoAccrualLine::query()->where('contract_id', $contract->id)->where('status', LineStatus::Accrued->value)->exists()) {
                continue;
            }

            foreach ($contract->scales as $scale) {
                $earned = $this->earnings->scaleEarned($scale, $contract, $end);
                $lines[] = $this->line($contract, SourceType::Scale, $scale->id, $scale->code.' · '.$scale->name, $earned['earned'], $earned['base_value'], $earned['rate_percent']);
            }

            foreach ($contract->promotions as $promotion) {
                if ($promotion->status === PromotionStatus::Draft) {
                    continue;
                }

                $earned = $this->earnings->promotionEarned($promotion, $contract, $end);
                $lines[] = $this->line($contract, SourceType::Promotion, $promotion->id, $promotion->code.' · '.$promotion->name, $earned['earned'], $earned['base_value'], $earned['rate_percent']);
            }
        }

        $lines = array_values(array_filter($lines, static fn (array $line): bool => ! Money::isZero($line['delta_amount'])));
        $total = Money::sum(array_column($lines, 'delta_amount'));

        return [
            'status' => 'preview',
            'run' => null,
            'period_key' => $key,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'lines' => $lines,
            'total_accrued' => $total,
            'contracts_count' => count(array_unique(array_column($lines, 'contract_id'))),
            'contracts' => $contracts->keyBy('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(GdoContract $contract, SourceType $type, string $sourceId, string $label, string $earned, string $base, ?string $rate): array
    {
        $cumulative = Money::round($earned, 2);
        $previous = Money::round($this->covered($type, $sourceId), 2);

        return [
            'contract_id' => $contract->id,
            'contract_code' => $contract->code,
            'currency' => $contract->currency,
            'source_type' => $type->value,
            'source_id' => $sourceId,
            'source_label' => $label,
            'base_value' => $base,
            'earned_cumulative' => $cumulative,
            'previously_accrued' => $previous,
            'delta_amount' => Money::sub($cumulative, $previous),
            'applied_rate_percent' => $rate,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function post(string $tenantId, array $result, ?string $userId): array
    {
        /** @var Collection<string, GdoContract> $contracts */
        $contracts = $result['contracts'];
        $run = GdoAccrualRun::create([
            'tenant_id' => $tenantId,
            'period_key' => $result['period_key'],
            'period_start' => $result['period_start'],
            'period_end' => $result['period_end'],
            'status' => RunStatus::Posted->value,
            'unique_key' => $tenantId.':'.$result['period_key'],
            'total_accrued' => $result['total_accrued'],
            'contracts_count' => $result['contracts_count'],
            'run_by' => $userId,
            'posted_at' => now(),
            'created_by' => $userId,
        ]);

        $journalLines = [];

        foreach ($result['lines'] as $line) {
            GdoAccrualLine::create([...array_diff_key($line, ['contract_code' => 1]), 'tenant_id' => $tenantId, 'run_id' => $run->id, 'status' => LineStatus::Accrued->value, 'created_by' => $userId]);
            $contract = $contracts[$line['contract_id']];
            $expense = $this->accounts->resolve($contract, 'expense');
            $provision = $this->accounts->resolve($contract, 'provision');
            $key = $expense.'|'.$provision;
            $journalLines[$key] = ['expense' => $expense, 'provision' => $provision, 'amount' => Money::add($journalLines[$key]['amount'] ?? '0', $line['delta_amount'])];
        }

        $entries = [];

        foreach ($journalLines as $row) {
            if (Money::isZero($row['amount'])) {
                continue;
            }

            $debitExpense = Money::isPositive($row['amount']);
            $amount = Money::abs($row['amount']);
            $entries[] = ['account_id' => $row['expense'], 'debit' => $debitExpense ? $amount : '0', 'credit' => $debitExpense ? '0' : $amount, 'description' => 'GDO rebates and contributions accrual '.$result['period_key']];
            $entries[] = ['account_id' => $row['provision'], 'debit' => $debitExpense ? '0' : $amount, 'credit' => $debitExpense ? $amount : '0', 'description' => 'Provision for credit notes to be issued '.$result['period_key']];
        }

        if ($entries !== []) {
            $run->update(['journal_entry_id' => $this->ledger->post($tenantId, (string) $result['period_end'], 'accrual', $run->id, 'GDO accrual '.$result['period_key'], $entries, $userId)]);
        }

        event(new AccrualRunPosted($run));

        return [...array_diff_key($result, ['contracts' => 1]), 'status' => 'posted', 'run' => $run->refresh()->load('lines')];
    }
}
