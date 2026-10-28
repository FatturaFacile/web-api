<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BankStatementLine extends BaseEnterpriseModel
{
    protected $table = 'bank_statement_lines';

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'reconciled' => 'boolean',
            'reconciled_amount' => 'decimal:2',
        ];
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class, 'bank_statement_line_id');
    }
}
