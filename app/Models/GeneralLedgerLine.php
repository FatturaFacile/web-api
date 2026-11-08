<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row of the legacy ledger tables (`general_ledger_entries` / `general_ledger_lines`). Nothing writes them any more:
 * the one ledger is the journal of the General Ledger module, and the existing rows are copied there by the migration
 * `2027_04_12_000200_migrate_legacy_general_ledger_entries` (see App\Services\Accounting\LegacyLedgerMigrator). The
 * model stays to read the history; the rows are never deleted.
 *
 * @deprecated Use Modules\GeneralLedger\Models\JournalEntry / JournalLine.
 */
class GeneralLedgerLine extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'general_ledger_lines';

    protected $fillable = [
        'general_ledger_entry_id',
        'account_id',
        'line_number',
        'debit',
        'credit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<GeneralLedgerEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(GeneralLedgerEntry::class, 'general_ledger_entry_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
