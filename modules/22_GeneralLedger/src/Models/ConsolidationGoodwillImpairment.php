<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The impairment of the goodwill of an acquisition charged in one fiscal year, entered by the user in group currency.
 *
 * @property int $fiscal_year
 * @property string $amount
 */
final class ConsolidationGoodwillImpairment extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_goodwill_impairments';

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'amount' => 'decimal:4'];
    }
}
