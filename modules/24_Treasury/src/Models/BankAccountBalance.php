<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BankAccountBalance extends BaseEnterpriseModel
{
    protected $table = 'bank_account_balances';

    protected function casts(): array
    {
        return [
            'balance_date' => 'date',
            'ledger_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
