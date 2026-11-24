<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final class WorkflowApprovalGeneralLedgerListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly PeriodClosingServiceInterface $closingService
    ) {}

    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        if ($event->definitionCode === 'JOURNAL_ENTRY_APPROVAL' || $event->aggregateType === 'journal_entry') {
            $journal = JournalEntry::find($event->aggregateId);
            if ($journal && $journal->status === 'draft') {
                $this->postingService->postDraft((string) $journal->id, 'workflow_engine');
            }
        } elseif ($event->definitionCode === 'PERIOD_CLOSING_APPROVAL' || $event->aggregateType === 'fiscal_period') {
            $this->closingService->closePeriod($event->aggregateId, 'workflow_engine');
        }
    }

    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        if ($event->definitionCode === 'JOURNAL_ENTRY_APPROVAL' || $event->aggregateType === 'journal_entry') {
            $journal = JournalEntry::find($event->aggregateId);
            if ($journal && $journal->status === 'draft') {
                $journal->update(['status' => 'rejected']);
            }
        }
    }
}
