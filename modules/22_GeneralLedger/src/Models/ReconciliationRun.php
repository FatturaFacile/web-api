<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReconciliationRun extends BaseEnterpriseModel
{
    protected $table = 'reconciliation_runs';

    protected function casts(): array
    {
        return [
            'gl_balance' => 'decimal:4',
            'subledger_balance' => 'decimal:4',
            'variance_amount' => 'decimal:4',
            'reconciled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'reconciliation_run_id');
    }
}
