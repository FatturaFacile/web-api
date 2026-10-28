<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\GeneralLedger\Models\ChartOfAccount;

final class BankAccount extends BaseEnterpriseModel
{
    protected $table = 'bank_accounts';

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'overdraft_limit' => 'decimal:2',
            'credit_line_limit' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_pool_header' => 'boolean',
        ];
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function signatories(): HasMany
    {
        return $this->hasMany(BankAccountSignatory::class, 'bank_account_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(BankAccountBalance::class, 'bank_account_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'bank_account_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class, 'bank_account_id');
    }

    public function paymentBatches(): HasMany
    {
        return $this->hasMany(PaymentBatch::class, 'bank_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }
}
