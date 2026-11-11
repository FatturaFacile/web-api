<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A fair-value adjustment at acquisition on an account of the group chart: `amount` is the increase of the balance of
 * the account on its normal side (an asset up, a liability up), in group currency at the acquisition date.
 *
 * @property string $account_code
 * @property string|null $account_name
 * @property string $account_type
 * @property string $amount
 * @property int|null $useful_life_years
 */
final class ConsolidationFairValueAdjustment extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_fair_value_adjustments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'useful_life_years' => 'integer'];
    }
}
