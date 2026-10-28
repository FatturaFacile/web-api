<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CashPool extends BaseEnterpriseModel
{
    protected $table = 'cash_pools';

    protected function casts(): array
    {
        return [
            'target_balance_floor' => 'decimal:2',
            'target_balance_ceiling' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function headerAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'header_bank_account_id');
    }

    public function intercompanyFundings(): HasMany
    {
        return $this->hasMany(IntercompanyFunding::class, 'cash_pool_id');
    }
}
