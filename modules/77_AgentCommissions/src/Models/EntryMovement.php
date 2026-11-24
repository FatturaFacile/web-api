<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A change of the eligible amount of a ledger entry: accrual, release, reversal or credit note adjustment.
 *
 * @property string $entry_id
 * @property string $kind
 * @property string $amount
 * @property Carbon $occurred_on
 * @property string|null $note
 */
final class EntryMovement extends BaseEnterpriseModel
{
    protected $table = 'agc_entry_movements';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }
}
