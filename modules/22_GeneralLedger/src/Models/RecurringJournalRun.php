<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due date of a recurring journal and the entry posted for it. Unique per date, so a run is never posted twice.
 */
final class RecurringJournalRun extends BaseEnterpriseModel
{
    protected $table = 'gl_recurring_journal_runs';

    protected function casts(): array
    {
        return ['run_date' => 'date'];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
