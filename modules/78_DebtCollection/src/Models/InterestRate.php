<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Support\Carbon;

/**
 * A late-payment interest rate valid over a period; global rows are the statutory defaults.
 *
 * @property string $jurisdiction
 * @property string $kind
 * @property string $base_rate_percent
 * @property string $markup_percent
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $notes
 */
final class InterestRate extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'dc_interest_rates';

    protected function casts(): array
    {
        return ['base_rate_percent' => 'decimal:4', 'markup_percent' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}
