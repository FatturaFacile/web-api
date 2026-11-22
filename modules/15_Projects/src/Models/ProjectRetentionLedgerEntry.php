<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A movement of the guarantee retention (ritenuta a garanzia / Garantierückbehalt) of a billing contract: an amount
 * withheld by a SAL certificate or a milestone invoice, or an amount released to the contractor.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $billing_contract_id
 * @property string|null $sal_certificate_id
 * @property string $entry_type
 * @property string $amount
 * @property CarbonInterface $entry_date
 * @property string|null $reason
 * @property string|null $billing_event_id
 */
final class ProjectRetentionLedgerEntry extends BaseEnterpriseModel
{
    public const string Withheld = 'withheld';

    public const string Released = 'released';

    protected $table = 'project_retention_ledger_entries';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'entry_date' => 'date',
        ];
    }
}
