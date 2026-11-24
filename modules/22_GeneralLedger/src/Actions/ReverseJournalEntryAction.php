<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Actions;

use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;

final readonly class ReverseJournalEntryAction
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService
    ) {}

    public function execute(string $journalEntryId, string $reversalDate, ?string $reason = null, ?string $actorUserId = null): JournalEntry
    {
        return $this->postingService->reverseJournal($journalEntryId, $reversalDate, $reason, $actorUserId);
    }
}
