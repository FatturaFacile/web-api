<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One booking of the prudential bad debt provision to the general ledger. The posting books the difference between the
 * provision required at `as_of` and what the earlier postings of the same currency already booked, so the provision
 * account always shows the required provision and a repeated run books nothing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $as_of
 * @property string $currency
 * @property string $total_provision
 * @property string $previous_provision
 * @property string $adjustment_amount
 * @property string|null $journal_entry_id
 * @property array<string, mixed>|null $breakdown
 */
final class ReceivableProvisionPosting extends BaseEnterpriseModel
{
    protected $table = 'receivable_provision_postings';

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'total_provision' => 'decimal:2',
            'previous_provision' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'breakdown' => 'array',
        ];
    }
}
