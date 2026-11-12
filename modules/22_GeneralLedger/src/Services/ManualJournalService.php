<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Journals written by hand (prima nota manuale), recurring runs and accruals. They go through the posting service
 * like every other entry; on top of it this service enforces the rules of the chart of accounts for manual postings
 * and the four-eyes principle: when the tenant requires it, a manual journal waits for the approval of a person other
 * than the one who wrote or submitted it.
 */
final readonly class ManualJournalService
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private LedgerSettingsService $settings,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * Saves a manual journal. Asked as `posted`, it is posted at once, or kept waiting for approval when the tenant
     * requires a second person for its amount; asked as `draft`, it is only saved.
     *
     * @param  array<string, mixed>  $data  validated journal data with `tenant_id` and `lines`
     *
     * @throws ValidationException when a line breaks a rule of its account
     */
    public function create(array $data, ?string $actorId): JournalEntry
    {
        $tenantId = (string) $data['tenant_id'];
        $this->assertManualLines($tenantId, $data['lines'] ?? []);
        $requested = $data['status'] ?? 'posted';
        $needsApproval = $requested === 'posted' && $this->settings->approvalRequiredFor($tenantId, $this->totalDebit($data['lines'] ?? []));
        $data['status'] = $needsApproval ? 'pending_approval' : $requested;

        return DB::transaction(function () use ($data, $actorId, $needsApproval): JournalEntry {
            $entry = $this->postingService->createAndPost($data, $actorId);

            if ($needsApproval) {
                $entry->update(['submitted_at' => now(), 'submitted_by' => $actorId]);
                $this->audit($entry, 'submitted_for_approval', null);
            }

            return $entry->refresh()->load('lines');
        });
    }

    /**
     * Sends a draft to approval.
     *
     * @throws LedgerConflictException when the journal is not a draft
     */
    public function submit(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId): JournalEntry {
            $entry = JournalEntry::whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status !== 'draft') {
                throw new LedgerConflictException(__('general_ledger_errors.journal.submit_only_draft', ['status' => $entry->status]));
            }

            $before = $entry->toArray();
            $entry->update(['status' => 'pending_approval', 'submitted_at' => now(), 'submitted_by' => $actorId, 'rejected_at' => null, 'rejected_by' => null, 'rejection_reason' => null]);
            $this->audit($entry, 'submitted_for_approval', $before);

            return $entry->load('lines');
        });
    }

    /**
     * Approves a journal waiting for approval and posts it. The approver must be another person than the author and
     * the submitter.
     *
     * @throws AuthorizationException when the approver wrote or submitted the journal
     * @throws LedgerConflictException when the journal is not waiting for approval
     */
    public function approve(string $journalEntryId, string $actorId): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId): JournalEntry {
            $entry = JournalEntry::whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status !== 'pending_approval') {
                throw new LedgerConflictException(__('general_ledger_errors.journal.approve_only_pending', ['status' => $entry->status]));
            }

            if (in_array($actorId, array_filter([(string) $entry->created_by, (string) $entry->submitted_by]), true)) {
                throw new AuthorizationException(__('general_ledger_errors.journal.four_eyes'));
            }

            $entry->update(['approved_at' => now(), 'approved_by' => $actorId]);
            $posted = $this->postingService->postDraft((string) $entry->id, $actorId);
            $this->audit($posted, 'approved', null);

            return $posted->refresh()->load('lines');
        });
    }

    /**
     * Rejects a journal waiting for approval; it stays in the ledger as rejected, never posted.
     *
     * @throws LedgerConflictException when the journal is not waiting for approval
     */
    public function reject(string $journalEntryId, string $actorId, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $actorId, $reason): JournalEntry {
            $entry = JournalEntry::whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status !== 'pending_approval') {
                throw new LedgerConflictException(__('general_ledger_errors.journal.reject_only_pending', ['status' => $entry->status]));
            }

            $before = $entry->toArray();
            $entry->update(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actorId, 'rejection_reason' => $reason]);
            $this->audit($entry, 'rejected', $before);

            return $entry->load('lines');
        });
    }

    /**
     * Posts a draft directly, when the tenant does not require approval for its amount.
     *
     * @throws LedgerConflictException when the journal needs approval first
     */
    public function postDraft(string $journalEntryId, string $actorId): JournalEntry
    {
        $entry = JournalEntry::findOrFail($journalEntryId);
        $total = (string) $entry->lines()->sum(DB::raw(FunctionalAmount::debit()));

        if ($entry->status === 'pending_approval' || ($entry->status === 'draft' && $this->settings->approvalRequiredFor((string) $entry->tenant_id, $total))) {
            throw new LedgerConflictException(__('general_ledger_errors.journal.needs_second_approval'));
        }

        return $this->postingService->postDraft($journalEntryId, $actorId);
    }

    /**
     * The rules of the chart of accounts for lines written by hand: the account must be active and open to manual
     * postings, and carry a cost centre when the account requires one.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function assertManualLines(string $tenantId, array $lines): void
    {
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_filter(array_map(static fn (array $line): string => (string) ($line['account_id'] ?? ''), $lines))))
            ->get()
            ->keyBy(static fn (ChartOfAccount $account): string => (string) $account->id);
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            $account = $accounts->get((string) ($line['account_id'] ?? ''));

            if ($account === null) {
                $errors["lines.{$index}.account_id"] = [__('general_ledger_errors.journal.account_not_in_chart')];

                continue;
            }

            if ($account->is_active === false) {
                $errors["lines.{$index}.account_id"] = [__('general_ledger_errors.journal.account_inactive', ['code' => $account->account_code])];
            } elseif ($account->allows_manual_posting === false) {
                $errors["lines.{$index}.account_id"] = [__('general_ledger_errors.journal.account_automatic_only', ['code' => $account->account_code])];
            } elseif ($account->is_cost_center_mandatory === true && empty($line['cost_center_id'])) {
                $errors["lines.{$index}.cost_center_id"] = [__('general_ledger_errors.journal.cost_center_required', ['code' => $account->account_code])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function totalDebit(array $lines): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $total = Decimal::add($total, Decimal::normalize((string) ($line['debit_amount'] ?? '0')));
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function audit(JournalEntry $entry, string $action, ?array $before): void
    {
        $this->auditLedger->record(
            action: $action,
            model: $entry,
            payloadBefore: $before,
            payloadAfter: $entry->toArray(),
            moduleCode: 'MODULE_22_GENERAL_LEDGER'
        );
    }
}
