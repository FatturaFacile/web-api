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

    public function postDraft(string $journalEntryId, ?string $actorUserId = null): JournalEntry;

    public function reverseJournal(string $journalEntryId, string $reversalDate, ?string $reason = null, ?string $actorUserId = null): JournalEntry;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function validateBalance(array $lines): bool;
}
