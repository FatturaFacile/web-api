<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A state where the company must collect sales tax (physical presence or economic nexus) from a date, until it ends.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $state
 * @property string $nexus_type
 * @property Carbon $established_on
 * @property Carbon|null $ended_on
 * @property string|null $registration_number
 * @property string|null $sales_threshold
 * @property int|null $transactions_threshold
 */
final class UsTaxNexus extends BaseEnterpriseModel
{
    public const array Types = ['physical', 'economic', 'affiliate', 'marketplace'];

    protected $table = 'us_tax_nexus';

    protected function casts(): array
    {
        return [
            'established_on' => 'date',
            'ended_on' => 'date',
            'sales_threshold' => 'decimal:2',
            'transactions_threshold' => 'integer',
        ];
    }
}
