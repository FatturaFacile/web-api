<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Support\Facades\DB;

/**
 * The lines of a posted journal entry are immutable, except their clearing.
 */
trait ProtectsPostedJournalLine
{
    use ProtectsRecords;

    /**
     * @var list<string>
     */
    private const array CLEARING_COLUMNS = ['is_cleared', 'clearing_reference', 'updated_at'];

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation === 'create') {
            return null;
        }

        $posted = DB::table('journal_entries')->where('id', $this->getOriginal('journal_entry_id'))->whereIn('status', ['posted', 'reversed'])->exists();

        if (! $posted || ($operation === 'update' && ! $this->changesOutside(self::CLEARING_COLUMNS))) {
            return null;
        }

        return 'The lines of a posted journal entry are immutable.';
    }
}
