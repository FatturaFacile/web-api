<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BankAccountSignatory extends BaseEnterpriseModel
{
    protected $table = 'bank_account_signatories';

    protected function casts(): array
    {
        return [
            'single_transaction_limit' => 'decimal:2',
            'daily_transaction_limit' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
