<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\GeneralLedger\Models\JournalEntry;

final class LoanDrawdown extends BaseEnterpriseModel
{
    protected $table = 'loan_drawdowns';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'drawdown_date' => 'date',
        ];
    }

    public function loanAgreement(): BelongsTo
    {
        return $this->belongsTo(LoanAgreement::class, 'loan_agreement_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_bank_account_id');
    }

    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }
}
