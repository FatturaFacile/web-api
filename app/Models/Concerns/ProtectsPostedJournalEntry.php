<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Database\Concerns\ProtectsRecords;

/**
 * A posted journal entry is corrected by reversing it: from posted it may only step to reversed, and only the
 * reversal columns move.
 */
trait ProtectsPostedJournalEntry
{
    use ProtectsRecords;

    /**
     * @var list<string>
     */
    private const array REVERSAL_COLUMNS = ['status', 'reversal_date', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

    protected function recordProtectionViolation(string $operation): ?string
    {
        $original = $this->getOriginal('status');

        if ($operation === 'create' || ! in_array($original, ['posted', 'reversed'], true)) {
            return null;
        }

        if ($operation === 'update' && $original === 'posted' && in_array($this->getAttribute('status'), ['posted', 'reversed'], true) && ! $this->changesOutside(self::REVERSAL_COLUMNS)) {
            return null;
        }

        return 'A posted journal entry is immutable: reverse it.';
    }
}
