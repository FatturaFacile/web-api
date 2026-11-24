<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LedgerBudgetLine extends BaseEnterpriseModel
{
    protected $table = 'gl_budget_lines';

    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
