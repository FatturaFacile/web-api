<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Events\RevenueRecognizedEvent;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueRecognitionEntry;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;
use Throwable;

/**
 * IFRS 15 revenue recognition of the subscriptions, backed by issued documents.
 *
 * Each invoice of a subscription credits the contract liability (deferred revenue). Its lines become performance
 * obligations of the revenue contract of the subscription, spread over their service period in monthly slices by
 * days (point-in-time items, such as setup fees and usage billed in arrears, in one slice). A refund credit note
 * releases its share of the liability at once and adds negative slices for the months it no longer covers.
 *
 * The monthly recognition posts, per month, one journal entry through the General Ledger posting service
 * (Dr deferred revenue / Cr revenue), only for slices whose document is issued; the slices are locked and flagged in
 * the same transaction as the entry, so a second run posts nothing twice.
 */
final readonly class RevenueRecognitionService implements RevenueRecognitionServiceInterface
{
    public const string JOURNAL_SOURCE = 'subscription_revenue';

    public const string RELEASE_SOURCE = 'subscription_credit_release';

    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private DoubleEntryPostingServiceInterface $posting,
        private FiscalPeriodResolver $periods,
        private SubscriptionAccounts $accounts,
        private SubscriptionHistory $history,
    ) {}

    /**
     * The revenue contract of a subscription, created on first use.
     */
    public function createRevenueContract(Subscription $subscription): RevenueContract
    {
        $contract = RevenueContract::query()->where('subscription_id', $subscription->id)->first();

        if ($contract !== null) {
            return $contract;
        }

        $termEnd = $subscription->term_end_date;

        return RevenueContract::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'contract_number' => 'REV-'.$subscription->subscription_number,
            'subscription_id' => $subscription->id,
            'total_transaction_price' => '0.00',
            'currency' => $subscription->currency,
            'inception_date' => ($subscription->start_date ?? CarbonImmutable::today())->toDateString(),
            'termination_date' => $termEnd?->toDateString(),
            'status' => 'active',
        ]);
    }

    /**
     * Allocates the transaction price of the contract to its obligations in proportion to their standalone selling
     * prices; the last obligation takes the rounding difference.
     */
    public function allocateTransactionPrice(RevenueContract $contract): void
    {
        $obligations = $contract->performanceObligations()->orderBy('created_at')->get();
        $totalSsp = Money::sum($obligations->map(fn (PerformanceObligation $pob): string => (string) $pob->getRawOriginal('standalone_selling_price')));

        if (Money::isZero($totalSsp)) {
            return;
        }

        $price = (string) $contract->getRawOriginal('total_transaction_price');
        $allocated = '0';
        $last = $obligations->count() - 1;

        foreach ($obligations->values() as $index => $pob) {
            $share = $index === $last
                ? Money::round2(Money::sub($price, $allocated))
                : Money::round2(Money::mul($price, Money::div((string) $pob->getRawOriginal('standalone_selling_price'), $totalSsp)));
            $allocated = Money::add($allocated, $share);
            $recognized = (string) $pob->getRawOriginal('recognized_revenue_to_date');

            $pob->update([
                'allocated_transaction_price' => $share,
                'deferred_revenue_balance' => Money::round2(Money::sub($share, $recognized)),
            ]);
        }
    }

    /**
     * Generates the slices of the obligations of the contract that have none yet.
     *
     * @return list<RevenueSchedule>
     */
    public function generateRevenueSchedules(RevenueContract $contract): array
    {
        $created = [];

        foreach ($contract->performanceObligations()->whereDoesntHave('revenueSchedules')->get() as $pob) {
            $created = [...$created, ...$this->slice($pob, (string) $pob->getRawOriginal('allocated_transaction_price'))];
        }

        return $created;
    }

    /**
     * Turns the lines of an issued document of the subscription into obligations and slices.
     *
     * @param  list<array{description: string, amount: string, method: string, start: CarbonInterface, end: CarbonInterface}>  $obligations  signed net amounts
     */
    public function scheduleDocument(Subscription $subscription, string $documentType, string $documentId, ?InvoiceSchedule $schedule, array $obligations): void
    {
        $contract = $this->createRevenueContract($subscription);
        $tenantId = (string) $subscription->tenant_id;
        $deferred = (string) $this->accounts->deferredRevenue($tenantId, $subscription->plan)->id;
        $revenue = (string) $this->accounts->revenue($tenantId, $subscription->plan)->id;
        $added = '0';

        foreach ($obligations as $index => $line) {
            $amount = Money::round2($line['amount']);

            if (Money::isZero($amount)) {
                continue;
            }

            $pob = PerformanceObligation::create([
                'tenant_id' => $tenantId,
                'revenue_contract_id' => $contract->id,
                'pob_code' => strtoupper(substr($documentType, 0, 3)).'-'.substr($documentId, -8).'-'.($index + 1),
                'description' => mb_substr($line['description'], 0, 255),
                'standalone_selling_price' => $amount,
                'allocated_transaction_price' => $amount,
                'recognition_method' => $line['method'] === 'point_in_time' ? 'point_in_time' : 'straight_line_over_time',
                'start_date' => $line['start']->toDateString(),
                'end_date' => $line['end']->toDateString(),
                'recognized_revenue_to_date' => '0.00',
                'deferred_revenue_balance' => $amount,
                'gl_revenue_account_id' => $revenue,
                'gl_deferred_account_id' => $deferred,
                'status' => 'in_progress',
                'invoice_schedule_id' => $schedule?->id,
                'source_document_type' => $documentType,
                'source_document_id' => $documentId,
            ]);

            $this->slice($pob, $amount);
            $added = Money::add($added, $amount);
        }

        $contract->update([
            'total_transaction_price' => Money::round2(Money::add((string) $contract->getRawOriginal('total_transaction_price'), $added)),
            'termination_date' => $subscription->term_end_date?->toDateString(),
        ]);
    }

    /**
     * Releases at once the deferred revenue a refund credit note gave back (Dr deferred revenue / Cr sales returns),
     * so the contra-revenue posted by the credit note is offset and the liability only holds what is still owed.
     * Idempotent per credit note.
     */
    public function releaseCredit(Subscription $subscription, SalesInvoice $creditNote, string $netAmount, CarbonInterface $date, ?string $actorUserId): ?JournalEntry
    {
        $tenantId = (string) $subscription->tenant_id;
        $amount = Money::round2(Money::abs($netAmount));

        if (Money::isZero($amount)) {
            return null;
        }

        $existing = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source_document_type', self::RELEASE_SOURCE)->where('source_document_id', $creditNote->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $day = $date->toDateString();

        return $this->posting->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $day)->id,
            'entry_number' => 'SUB-REL-'.strtoupper(Str::random(8)),
            'source_document_type' => self::RELEASE_SOURCE,
            'source_document_id' => (string) $creditNote->id,
            'document_number' => (string) $creditNote->invoice_number,
            'posting_date' => $day,
            'document_date' => $day,
            'journal_type' => 'standard',
            'description' => "Deferred revenue released by credit note {$creditNote->invoice_number} ({$subscription->subscription_number})",
            'lines' => [
                ['account_id' => (string) $this->accounts->deferredRevenue($tenantId, $subscription->plan)->id, 'debit_amount' => $amount, 'credit_amount' => '0', 'currency' => $subscription->currency, 'description' => 'Deferred revenue released'],
                ['account_id' => (string) $this->accounts->salesReturns($tenantId)->id, 'debit_amount' => '0', 'credit_amount' => $amount, 'currency' => $subscription->currency, 'description' => 'Credit note offset'],
            ],
        ], $actorUserId);
    }

    /**
     * Recognizes the slices due up to the period date, one journal entry per month.
     *
     * @return array{entries_count: int, total_amount: string, journal_entry_ids: list<string>, months: list<string>, errors: list<array{month: string, error: string}>}
     */
    public function recognizeRevenue(RecognizeRevenueDTO $dto): array
    {
        $asOf = $dto->periodDate !== null ? CarbonImmutable::parse($dto->periodDate) : CarbonImmutable::today();
        $months = RevenueSchedule::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('is_recognized', false)
            ->whereDate('period_date', '<=', $asOf->toDateString())
            ->whereHas('performanceObligation', fn ($query) => $query->whereNotNull('source_document_id'))
            ->distinct()
            ->orderBy('period_date')
            ->pluck('period_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values();

        $result = ['entries_count' => 0, 'total_amount' => '0.00', 'journal_entry_ids' => [], 'months' => [], 'errors' => []];

        foreach ($months as $month) {
            try {
                $outcome = DB::transaction(fn (): array => $this->recognizeMonth($dto, $month));
            } catch (Throwable $e) {
                $result['errors'][] = ['month' => $month, 'error' => mb_substr($e->getMessage(), 0, 500)];

                continue;
            }

            if ($outcome['entries'] === 0) {
                continue;
            }

            $result['entries_count'] += $outcome['entries'];
            $result['total_amount'] = Money::round2(Money::add($result['total_amount'], $outcome['amount']));
            $result['months'][] = $month;

            if ($outcome['journal_entry_id'] !== null) {
                $result['journal_entry_ids'][] = $outcome['journal_entry_id'];
            }
        }

        return $result;
    }

    /**
     * @return array{entries: int, amount: string, journal_entry_id: ?string}
     */
    private function recognizeMonth(RecognizeRevenueDTO $dto, string $month): array
    {
        $slices = RevenueSchedule::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('is_recognized', false)
            ->whereDate('period_date', $month)
            ->whereHas('performanceObligation', fn ($query) => $query->whereNotNull('source_document_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $obligations = PerformanceObligation::query()->whereIn('id', $slices->pluck('performance_obligation_id')->unique())->get()->keyBy('id');
        $issued = $this->issuedDocuments($obligations);
        $slices = $slices->filter(fn (RevenueSchedule $slice): bool => isset($issued[(string) $obligations[$slice->performance_obligation_id]?->source_document_id]));

        if ($slices->isEmpty()) {
            return ['entries' => 0, 'amount' => '0', 'journal_entry_id' => null];
        }

        $pairs = [];
        $byObligation = [];

        foreach ($slices as $slice) {
            $pob = $obligations[$slice->performance_obligation_id];
            $amount = (string) $slice->getRawOriginal('scheduled_amount');
            $key = $pob->gl_deferred_account_id.'|'.$pob->gl_revenue_account_id;
            $pairs[$key] = Money::add($pairs[$key] ?? '0', $amount);
            $byObligation[(string) $pob->id] = Money::add($byObligation[(string) $pob->id] ?? '0', $amount);
        }

        $currency = (string) (RevenueContract::query()->whereKey($obligations->first()?->revenue_contract_id)->value('currency') ?? 'EUR');
        $lines = [];

        foreach ($pairs as $key => $amount) {
            [$deferred, $revenue] = explode('|', $key);
            $value = Money::round2(Money::abs($amount));

            if (Money::isZero($value)) {
                continue;
            }

            $positive = Money::isPositive($amount);
            $lines[] = ['account_id' => $positive ? $deferred : $revenue, 'debit_amount' => $value, 'credit_amount' => '0', 'currency' => $currency, 'description' => $positive ? 'Deferred revenue recognized' : 'Revenue reversed to deferred'];
            $lines[] = ['account_id' => $positive ? $revenue : $deferred, 'debit_amount' => '0', 'credit_amount' => $value, 'currency' => $currency, 'description' => $positive ? 'Subscription revenue' : 'Deferred revenue restored'];
        }

        $journalId = null;

        if ($lines !== []) {
            $journal = $this->posting->createAndPost([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId ?? $dto->tenantId,
                'fiscal_period_id' => $this->periods->forDate($dto->tenantId, $month)->id,
                'entry_number' => 'SUB-REV-'.CarbonImmutable::parse($month)->format('Ym').'-'.strtoupper(Str::random(6)),
                'source_document_type' => self::JOURNAL_SOURCE,
                'source_document_id' => (string) Str::uuid7(),
                'document_number' => 'Subscription revenue '.CarbonImmutable::parse($month)->format('Y-m'),
                'posting_date' => $month,
                'document_date' => $month,
                'journal_type' => 'standard',
                'description' => 'Subscription revenue recognized for '.CarbonImmutable::parse($month)->format('Y-m'),
                'lines' => $lines,
            ], $dto->executedBy);
            $journalId = (string) $journal->id;
        }

        $total = Money::sum($byObligation);

        foreach ($slices as $slice) {
            $slice->update([
                'is_recognized' => true,
                'recognized_at' => now(),
                'recognized_amount' => (string) $slice->getRawOriginal('scheduled_amount'),
                'journal_entry_id' => $journalId,
            ]);
        }

        foreach ($byObligation as $pobId => $amount) {
            $pob = $obligations[$pobId];
            $recognized = Money::round2(Money::add((string) $pob->getRawOriginal('recognized_revenue_to_date'), $amount));
            $deferredBalance = Money::round2(Money::sub((string) $pob->getRawOriginal('allocated_transaction_price'), $recognized));

            $pob->update([
                'recognized_revenue_to_date' => $recognized,
                'deferred_revenue_balance' => $deferredBalance,
                'status' => Money::isZero($deferredBalance) ? 'satisfied' : 'in_progress',
            ]);

            $entry = RevenueRecognitionEntry::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId ?? $dto->tenantId,
                'performance_obligation_id' => $pob->id,
                'recognition_date' => $month,
                'amount' => Money::round2($amount),
                'currency' => $currency,
                'gl_journal_entry_id' => $journalId,
                'audit_hash' => hash('sha256', $pob->id.'|'.$month.'|'.Money::round2($amount).'|'.$journalId),
            ]);

            $this->history->publish(new RevenueRecognizedEvent(
                recognitionEntryId: (string) $entry->id,
                performanceObligationId: (string) $pob->id,
                amount: (float) Money::round2($amount),
                recognitionDate: $month,
                currency: $currency,
                tenantId: $dto->tenantId,
                companyId: (string) ($dto->companyId ?? $dto->tenantId)
            ));
        }

        if ($journalId !== null) {
            $this->auditLedger->record('REVENUE_RECOGNIZED', JournalEntry::withoutGlobalScopes()->findOrFail($journalId), null, ['month' => $month, 'amount' => Money::round2($total), 'slices' => $slices->count()], 'MODULE_19_SUB');
        }

        return ['entries' => count($byObligation), 'amount' => $total, 'journal_entry_id' => $journalId];
    }

    /**
     * Ids of the issued documents among the sources of the obligations.
     *
     * @param  Collection<string, PerformanceObligation>  $obligations
     * @return array<string, true>
     */
    private function issuedDocuments(Collection $obligations): array
    {
        $ids = $obligations->pluck('source_document_id')->filter()->unique()->values();

        $issued = SalesInvoice::withoutGlobalScopes()->whereIn('id', $ids)->where('status', 'posted')->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return array_fill_keys($issued, true);
    }

    /**
     * Monthly slices of an obligation by days of service; one slice for point-in-time obligations.
     *
     * @return list<RevenueSchedule>
     */
    private function slice(PerformanceObligation $pob, string $amount): array
    {
        $start = CarbonImmutable::parse($pob->start_date);
        $end = CarbonImmutable::parse($pob->end_date);

        if ($pob->recognition_method === 'point_in_time' || $end->lt($start)) {
            return [$this->createSlice($pob, $start->endOfMonth(), $amount)];
        }

        $totalDays = BillingCalendar::days($start, $end);
        $slices = [];
        $allocated = '0';
        $cursor = $start;

        while ($cursor->lte($end)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $segmentEnd = $monthEnd->lt($end) ? $monthEnd : $end;
            $isLast = $segmentEnd->gte($end);
            $share = $isLast
                ? Money::round2(Money::sub($amount, $allocated))
                : Money::round2(Money::mul($amount, Money::div((string) BillingCalendar::days($cursor, $segmentEnd), (string) $totalDays)));
            $allocated = Money::add($allocated, $share);
            $slices[] = $this->createSlice($pob, $monthEnd, $share);
            $cursor = $segmentEnd->addDay();
        }

        return $slices;
    }

    private function createSlice(PerformanceObligation $pob, CarbonImmutable $periodDate, string $amount): RevenueSchedule
    {
        return RevenueSchedule::create([
            'tenant_id' => $pob->tenant_id,
            'performance_obligation_id' => $pob->id,
            'period_date' => $periodDate->toDateString(),
            'scheduled_amount' => Money::round2($amount),
            'recognized_amount' => '0.00',
            'is_recognized' => false,
        ]);
    }
}
