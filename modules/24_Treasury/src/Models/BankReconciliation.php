<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BankReconciliation extends BaseEnterpriseModel
{
    protected $table = 'bank_reconciliations';

    protected function casts(): array
    {
        return [
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'bank_statement_balance' => 'decimal:2',
            'gl_book_balance' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class, 'bank_reconciliation_id');
    }
}
