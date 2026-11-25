<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One monthly share of the deferred (risconto) entry fee of an agreement.
 *
 * @property string $agreement_id
 * @property string $period
 * @property string $amount
 * @property Carbon|null $recognized_at
 */
final class EntryFeeDeferral extends BaseEnterpriseModel
{
    protected $table = 'frn_entry_fee_deferrals';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recognized_at' => 'datetime',
        ];
    }
}
