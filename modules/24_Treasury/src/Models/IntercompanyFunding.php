<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Models\JournalEntry;

final class IntercompanyFunding extends BaseEnterpriseModel
{
    protected $table = 'intercompany_funding';

    protected function casts(): array
    {
        return [
            'transfer_amount' => 'decimal:2',
            'transfer_date' => 'date',
            'intercompany_interest_rate' => 'decimal:4',
        ];
    }

    public function cashPool(): BelongsTo
    {
        return $this->belongsTo(CashPool::class, 'cash_pool_id');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_bank_account_id');
    }

    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }

    public function getInterestRateAttribute(): float
    {
        return (float) $this->intercompany_interest_rate;
    }
}
