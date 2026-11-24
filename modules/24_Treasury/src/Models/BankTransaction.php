<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Models\JournalEntry;

final class BankTransaction extends BaseEnterpriseModel
{
    protected $table = 'bank_transactions';

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }
}
