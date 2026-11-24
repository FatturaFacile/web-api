<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BankGuarantee extends BaseEnterpriseModel
{
    protected $table = 'bank_guarantees';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission_rate_annual' => 'decimal:4',
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'collateral_cash_blocked' => 'decimal:2',
        ];
    }

    public function issuingAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'issuing_bank_account_id');
    }
}
