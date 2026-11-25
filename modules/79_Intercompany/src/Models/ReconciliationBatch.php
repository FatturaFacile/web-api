<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One run of the reciprocal reconciliation of a group at a date.
 *
 * @property Carbon $as_of_date
 * @property array<string, mixed>|null $totals
 * @property Carbon|null $created_at
 */
final class ReconciliationBatch extends BaseEnterpriseModel
{
    protected $table = 'ic_reconciliation_batches';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'totals' => 'array',
        ];
    }
}
