<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BankStatement extends BaseEnterpriseModel
{
    protected $table = 'bank_statements';

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'total_debits' => 'decimal:2',
            'total_credits' => 'decimal:2',
            'lines_count' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_statement_id');
    }
}
