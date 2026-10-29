<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerLedger extends BaseEnterpriseModel
{
    protected $table = 'customer_ledger';

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:4',
            'open_amount' => 'decimal:4',
            'dunning_level' => 'integer',
            'last_dunning_date' => 'date',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
