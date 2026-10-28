<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AccrualEntry extends BaseEnterpriseModel
{
    protected $table = 'accrual_entries';

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
            'monthly_amount' => 'decimal:4',
            'periods_count' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }
}
