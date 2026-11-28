<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Listeners;

use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\JournalReversedEvent;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\ValueObjects\BiTemporalDomainEventEnvelope;
use Modules\TimeTravel\Support\Instant;

/**
 * General ledger (domain 02): every posted journal entry (adjustments, closing and opening entries included) and every reversal is
 * appended to the event store with its lines. The valid time is the posting date; the recording time is when the ledger committed it.
 */
final class GeneralLedgerEventCapture
{
    use CapturesDomainEvents;

    public const array EVENT_TYPES = ['JournalEntryPosted', 'JournalEntryReversed'];

    public function onJournalPosted(JournalPostedEvent $event): void
    {
        $this->capture($event->tenantId, $event->journalEntryId, 'JournalEntryPosted', 'general_ledger.journal.posted');
    }

    public function onJournalReversed(JournalReversedEvent $event): void
    {
        $this->capture($event->tenantId, $event->reversalJournalEntryId, 'JournalEntryReversed', 'general_ledger.journal.reversed', ['reverses_journal_entry_id' => $event->originalJournalEntryId]);
    }

    /**
     * The envelope of an already posted journal entry (also used to backfill the history).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function envelope(JournalEntry $entry, string $eventType, array $extra = [], ?string $actor = null, array $metadata = []): BiTemporalDomainEventEnvelope
    {
        $lines = [];

        foreach ($entry->lines as $line) {
            $lines[] = [
                'account_id' => (string) $line->account_id,
                'debit' => bcadd((string) $line->debit_amount, '0', 6),
                'credit' => bcadd((string) $line->credit_amount, '0', 6),
            ];
        }

        $postingDate = substr((string) $entry->posting_date, 0, 10);

        return new BiTemporalDomainEventEnvelope(
            (string) $entry->tenant_id,
            AggregateType::GeneralLedger,
            (string) $entry->id,
            $eventType,
            Instant::startOfDay($postingDate),
            [
                'journal_entry_id' => (string) $entry->id,
                'entry_number' => (string) $entry->entry_number,
                'posting_date' => $postingDate,
                'description' => (string) $entry->description,
                'ledger_type' => (string) $entry->ledger_type,
                'journal_type' => (string) $entry->journal_type,
                'source_document_type' => $entry->source_document_type !== null ? (string) $entry->source_document_type : null,
                'source_document_id' => $entry->source_document_id !== null ? (string) $entry->source_document_id : null,
                'total_amount' => bcadd((string) $entry->total_amount, '0', 6),
                'lines' => $lines,
                ...$extra,
            ],
            $metadata,
            $actor ?? ($entry->posted_by !== null ? (string) $entry->posted_by : null),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function capture(string $tenantId, string $journalEntryId, string $eventType, string $source, array $extra = []): void
    {
        if (! $this->enabled($tenantId) || $this->recorder->exists($tenantId, AggregateType::GeneralLedger, $journalEntryId, self::EVENT_TYPES)) {
            return;
        }

        $entry = JournalEntry::withoutGlobalScopes()->with('lines')->where('tenant_id', $tenantId)->whereKey($journalEntryId)->first();

        if ($entry === null) {
            return;
        }

        $envelope = self::envelope($entry, $eventType, $extra, $this->actor($entry->posted_by !== null ? (string) $entry->posted_by : null), ['source_event' => $source]);
        $this->recorder->record($envelope);
    }
}
