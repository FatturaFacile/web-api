<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Contracts\RebateLedgerPosterInterface;
use Modules\Rebates\Models\RebateAccrual;
use Modules\Rebates\Models\RebateAccrualLine;
use Modules\Rebates\Models\RebateAccrualRun;
use Modules\Rebates\Models\RebateAgreement;
use Modules\Rebates\Models\RebateContribution;

/**
 * Periodic accrual of a supplier rebate (calcolo automatico e scritture di rateo). A run covers one accrual period of
 * the agreement: it accrues what the cumulative rebate gained since the previous runs, attributes the qualifying
 * invoices to the run and books the accrual in the general ledger when the agreement has its accounts.
 *
 * Idempotent: a period already covered by a posted run returns that run. Periods lock in order: a run cannot be
 * created before the end of the latest posted run, and only the latest run can be reversed, and only while none of its
 * accruals is part of a settlement proposal. Recalculating a period is a reversal followed by a new run.
 */
final readonly class SupplierAccrualRunService
{
    public function __construct(private RebateAccrualService $accruals, private RebateLedgerPosterInterface $ledger) {}

    /**
     * Period of the run that ends at the date for the accrual frequency of the agreement.
     *
     * @return array{period_start: Carbon, period_end: Carbon}
     */
    public function periodFor(RebateAgreement $agreement, Carbon $reference, ?Carbon $customStart = null): array
    {
        $end = $reference->copy()->startOfDay();

        $start = match ($agreement->accrual_frequency) {
            'quarterly' => $end->copy()->startOfQuarter(),
            'custom' => ($customStart ?? $agreement->starts_on)->copy()->startOfDay(),
            default => $end->copy()->startOfMonth(),
        };

        if ($start->lt($agreement->starts_on)) {
            $start = $agreement->starts_on->copy();
        }

        return ['period_start' => $start, 'period_end' => $end];
    }

    public function run(RebateAgreement $agreement, Carbon $periodEnd, ?Carbon $periodStart, ?string $userId): RebateAccrualRun
    {
        return DB::transaction(function () use ($agreement, $periodEnd, $periodStart, $userId): RebateAccrualRun {
            $locked = RebateAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($locked->direction !== RebateAgreement::Purchase) {
                throw ValidationException::withMessages(['agreement' => [__('rebate_errors.run.purchase_only')]]);
            }

            $period = $this->periodFor($locked, $periodEnd, $periodStart);

            $existing = RebateAccrualRun::query()->where('agreement_id', $locked->id)->where('status', RebateAccrualRun::Posted)->whereDate('period_end', $period['period_end']->toDateString())->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }

            $latest = RebateAccrualRun::query()->where('agreement_id', $locked->id)->where('status', RebateAccrualRun::Posted)->orderByDesc('period_end')->first();

            if ($latest !== null && $period['period_end']->lte($latest->period_end)) {
                throw ValidationException::withMessages(['period_end' => [__('rebate_errors.run.period_locked', ['date' => $latest->period_end->toDateString()])]]);
            }

            $run = RebateAccrualRun::create([
                'tenant_id' => $locked->tenant_id,
                'agreement_id' => $locked->id,
                'period_start' => $period['period_start']->toDateString(),
                'period_end' => $period['period_end']->toDateString(),
                'status' => RebateAccrualRun::Posted,
                'created_by' => $userId,
            ]);

            $result = $this->accruals->calculate($locked, $period['period_end'], (string) $run->id);
            $journalId = null;

            if ($result['accrual'] !== null && $locked->accrual_account_id !== null && $locked->income_account_id !== null) {
                $journalId = $this->ledger->postAccrual(
                    (string) $locked->tenant_id,
                    $period['period_end']->toDateString(),
                    $result['delta'],
                    (string) $locked->accrual_account_id,
                    (string) $locked->income_account_id,
                    (string) $run->id,
                    __('rebate_errors.run.journal_description', ['code' => $locked->code, 'to' => $period['period_end']->toDateString()]),
                    $userId,
                );
                $result['accrual']->forceFill(['journal_entry_id' => $journalId])->save();
            }

            $run->forceFill([
                'base_amount' => $result['base_amount'],
                'cumulative_rebate' => $result['cumulative_rebate'],
                'delta_amount' => $result['delta'],
                'journal_entry_id' => $journalId,
            ])->save();

            foreach ($this->accruals->qualifyingInvoices($locked, $period['period_end']) as $invoice) {
                $credit = $invoice->document_type === 'TD04';
                $net = (string) $invoice->total_net;

                RebateAccrualLine::create([
                    'tenant_id' => $locked->tenant_id,
                    'accrual_run_id' => $run->id,
                    'passive_invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'document_type' => $invoice->document_type,
                    'invoice_date' => $invoice->date->toDateString(),
                    'net_amount' => $credit ? Decimal::neg($net) : $net,
                ]);
            }

            return $run->refresh()->load('lines');
        });
    }

    /**
     * Reverses the latest run: its accruals are cancelled, the contributions it accrued are planned again and the ledger
     * entry is reversed.
     */
    public function reverse(RebateAccrualRun $run, string $reason, ?string $userId): RebateAccrualRun
    {
        return DB::transaction(function () use ($run, $reason, $userId): RebateAccrualRun {
            $locked = RebateAccrualRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RebateAccrualRun::Posted) {
                throw ValidationException::withMessages(['run' => [__('rebate_errors.run.not_posted')]]);
            }

            $later = RebateAccrualRun::query()->where('agreement_id', $locked->agreement_id)->where('status', RebateAccrualRun::Posted)->whereDate('period_end', '>', $locked->period_end->toDateString())->exists();

            if ($later) {
                throw ValidationException::withMessages(['run' => [__('rebate_errors.run.not_latest')]]);
            }

            $accruals = RebateAccrual::query()->where('accrual_run_id', $locked->id)->lockForUpdate()->get();

            if ($accruals->contains(static fn (RebateAccrual $accrual): bool => $accrual->status !== RebateAccrual::Accrued)) {
                throw ValidationException::withMessages(['run' => [__('rebate_errors.run.already_settled')]]);
            }

            $reversalId = null;

            if ($locked->journal_entry_id !== null) {
                $reversalId = $this->ledger->reverse((string) $locked->journal_entry_id, now()->toDateString(), $reason, $userId);
            }

            RebateContribution::query()->whereIn('accrual_id', $accruals->pluck('id'))->update(['status' => RebateContribution::Planned, 'accrual_id' => null]);
            RebateAccrual::query()->whereIn('id', $accruals->pluck('id'))->update(['status' => RebateAccrual::Reversed]);

            $locked->forceFill(['status' => RebateAccrualRun::Reversed, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_journal_entry_id' => $reversalId])->save();

            return $locked->refresh()->load('lines');
        });
    }
}
