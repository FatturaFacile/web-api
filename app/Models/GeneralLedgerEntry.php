<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Row of the legacy ledger tables (`general_ledger_entries` / `general_ledger_lines`). Nothing writes them any more:
 * the one ledger is the journal of the General Ledger module, and the existing rows are copied there by the migration
 * `2027_04_12_000200_migrate_legacy_general_ledger_entries` (see App\Services\Accounting\LegacyLedgerMigrator). The
 * model stays to read the history; the rows are never deleted.
 *
 * @deprecated Use Modules\GeneralLedger\Models\JournalEntry / JournalLine.
 */
class GeneralLedgerEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'general_ledger_entries';

    protected $fillable = [
        'tenant_id',
        'entry_number',
        'date',
        'description',
        'reference_type',
        'reference_id',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return HasMany<GeneralLedgerLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GeneralLedgerLine::class, 'general_ledger_entry_id');
    }
}
