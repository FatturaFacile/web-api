<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Contracts;

use Modules\GeneralLedger\Models\JournalEntry;

interface DoubleEntryPostingServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createAndPost(array $data, ?string $actorUserId = null): JournalEntry;

    /**
     * The year-end closing entry, the only one allowed in a closed period. It is not reachable from the API.
     *
     * @param  array<string, mixed>  $data
     */
    public function postYearEndClosing(array $data, string $actorUserId): JournalEntry;

    public function postDraft(string $journalEntryId, ?string $actorUserId = null): JournalEntry;

    /**
     * @param  bool  $bankBalanceManaged  The caller moves the balance of the bank account itself, as it did when the entry
     *                                    was posted: the reversal then announces no bank posting.
     */
    public function reverseJournal(string $journalEntryId, string $reversalDate, ?string $reason = null, ?string $actorUserId = null, bool $bankBalanceManaged = false): JournalEntry;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function validateBalance(array $lines): bool;
}
