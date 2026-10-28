<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReconciliationItem extends BaseEnterpriseModel
{
    protected $table = 'reconciliation_items';

    protected function casts(): array
    {
        return [
            'item_amount' => 'decimal:4',
            'variance_amount' => 'decimal:4',
            'is_matched' => 'boolean',
        ];
    }

    public function reconciliationRun(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
