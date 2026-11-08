<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Throwable;

/**
 * Automatic reversal (storno automatico) of the entries posted with a reversal date: accruals and deferrals, exchange
 * revaluations, reversing manual journals. On or after that date the entry is reversed through the posting service,
 * dated on the reversal date itself, so the reversal lands in the period of that date. An entry is reversed once only:
 * after the reversal its status is `reversed`.
 */
final readonly class ScheduledReversalService
{
    public function __construct(private DoubleEntryPostingServiceInterface $postingService) {}

    /**
     * Reverses every posted entry of the tenant whose reversal date is on or before the given day. A reversal that
     * cannot be posted (missing or closed period) is reported and the others go on.
     *
     * @return array{reversed: list<string>, failed: list<array{journal_entry_id: string, message: string}>}
     */
    public function processDue(string $tenantId, CarbonImmutable $asOf, ?string $actorId): array
    {
        $reversed = [];
        $failed = [];

        $due = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'posted')
            ->whereNotNull('auto_reverse_on')
            ->whereDate('auto_reverse_on', '<=', $asOf->toDateString())
            ->whereNull('deleted_at')
            ->orderBy('auto_reverse_on')
            ->get(['id', 'auto_reverse_on', 'entry_number']);

        foreach ($due as $entry) {
            try {
                $reversal = $this->postingService->reverseJournal(
                    (string) $entry->id,
                    CarbonImmutable::parse($entry->auto_reverse_on)->toDateString(),
                    'Automatic reversal',
                    $actorId
                );
                $reversed[] = (string) $reversal->id;
            } catch (Throwable $exception) {
                $failed[] = ['journal_entry_id' => (string) $entry->id, 'message' => $exception->getMessage()];
            }
        }

        return ['reversed' => $reversed, 'failed' => $failed];
    }
}
