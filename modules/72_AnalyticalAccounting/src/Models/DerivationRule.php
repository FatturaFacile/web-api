<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Record of the table analytical_derivation_rules.
 *
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
final class DerivationRule extends BaseEnterpriseModel
{
    protected $table = 'analytical_derivation_rules';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
