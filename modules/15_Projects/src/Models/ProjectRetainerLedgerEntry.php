<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One movement of the prepaid retainer / hour bank of a billing contract: a top-up (positive hours and amount) or a
 * consumption (hours and amount burned). The balances after the movement make the burn-down readable without a replay.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $billing_contract_id
 * @property string $entry_type
 * @property string|null $source_type
 * @property string|null $source_id
 * @property CarbonInterface $entry_date
 * @property string $hours
 * @property string $amount
 * @property string $balance_hours_after
 * @property string $balance_amount_after
 * @property string|null $note
 */
final class ProjectRetainerLedgerEntry extends BaseEnterpriseModel
{
    public const string TopUp = 'top_up';

    public const string Consumption = 'consumption';

    protected $table = 'project_retainer_ledger_entries';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'hours' => 'decimal:4',
            'amount' => 'decimal:4',
            'balance_hours_after' => 'decimal:4',
            'balance_amount_after' => 'decimal:4',
        ];
    }
}
