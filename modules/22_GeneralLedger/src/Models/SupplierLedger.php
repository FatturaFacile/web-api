<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupplierLedger extends BaseEnterpriseModel
{
    protected $table = 'supplier_ledger';

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:4',
            'open_amount' => 'decimal:4',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
