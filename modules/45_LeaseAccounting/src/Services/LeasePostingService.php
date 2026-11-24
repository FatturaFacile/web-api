<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeasePeriodPosting;
use Modules\LeaseAccounting\Models\LeaseScheduleLine;

/**
 * Posts the interest, depreciation and payment journals of the periods of a lease, in order, one journal per period.
 * It is idempotent: the next period to post is the one after the last posted, and a unique index (contract, period)
 * refuses a second posting of the same period, so repeating the run, or two runs at once, never post twice. Only
 * periods that have ended by the through date are posted. The last period closes the lease (active to expired).
 */
final readonly class LeasePostingService
{
    public function __construct(private LeaseJournalPoster $poster) {}

    /**
     * Posts every period of the lease that ended on or before the date and was not posted yet.
     *
     * @return list<LeasePeriodPosting> the postings written by this call, in period order
     *
     * @throws ValidationException when the lease cannot be posted or the ledger refuses a period (the periods before it stay posted)
     */
    public function postThrough(LeaseContract $contract, CarbonImmutable $through, ?string $actorId): array
    {
        if ($contract->status !== 'active' || $contract->isExempt()) {
            throw ValidationException::withMessages(['status' => [__('lease_errors.posting.not_postable', ['status' => $contract->status])]]);
        }

        $posted = [];

        while (($posting = $this->postNextPeriod((string) $contract->id, $through, $actorId)) !== null) {
            $posted[] = $posting;
        }

        return $posted;
    }

    /**
     * Posts the period after the last posted one, when it has ended by the through date; null when there is nothing to post.
     */
    private function postNextPeriod(string $contractId, CarbonImmutable $through, ?string $actorId): ?LeasePeriodPosting
    {
        try {
            return DB::transaction(function () use ($contractId, $through, $actorId): ?LeasePeriodPosting {
                $contract = LeaseContract::query()->whereKey($contractId)->lockForUpdate()->firstOrFail();

                if ($contract->status !== 'active') {
                    return null;
                }

                $next = (int) LeasePeriodPosting::query()->where('lease_contract_id', $contractId)->max('period_number') + 1;
                $line = LeaseScheduleLine::query()->where('lease_contract_id', $contractId)->where('status', 'scheduled')->where('period_number', $next)->first();

                if ($line === null || $line->period_end->toDateString() > $through->toDateString()) {
                    return null;
                }

                $entry = $this->poster->postPeriod($contract, $line->period_end->toDateString(), $next, (string) $line->id, (string) $line->interest, (string) $line->depreciation, (string) $line->payment, $actorId);
                $posting = LeasePeriodPosting::create([
                    'tenant_id' => $contract->tenant_id,
                    'lease_contract_id' => $contract->id,
                    'schedule_line_id' => $line->id,
                    'period_number' => $next,
                    'posting_date' => $line->period_end->toDateString(),
                    'journal_entry_id' => (string) $entry->id,
                    'interest' => $line->interest,
                    'depreciation' => $line->depreciation,
                    'payment' => $line->payment,
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                ]);
                $line->update(['status' => 'posted']);

                $isLast = ! LeaseScheduleLine::query()->where('lease_contract_id', $contractId)->where('status', 'scheduled')->exists();

                if ($isLast && $contract->canTransitionTo('expired')) {
                    $contract->update(['status' => 'expired']);
                }

                return $posting;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Posts every active lease of the company; a lease the ledger refuses is reported and does not stop the others.
     *
     * @return list<array{contract_id: string, number: string, posted_periods: int, error: string|null}>
     */
    public function runForTenant(string $tenantId, CarbonImmutable $through, ?string $actorId): array
    {
        $results = [];

        $contracts = LeaseContract::query()->where('tenant_id', $tenantId)->where('status', 'active')->where('exemption', 'none')->orderBy('number')->get();

        foreach ($contracts as $contract) {
            try {
                $postings = $this->postThrough($contract, $through, $actorId);
                $results[] = ['contract_id' => (string) $contract->id, 'number' => (string) $contract->number, 'posted_periods' => count($postings), 'error' => null];
            } catch (ValidationException $exception) {
                $results[] = ['contract_id' => (string) $contract->id, 'number' => (string) $contract->number, 'posted_periods' => 0, 'error' => collect($exception->errors())->flatten()->first()];
            }
        }

        return $results;
    }
}
