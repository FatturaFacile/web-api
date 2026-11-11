<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A line of a consolidation adjustment, on an account code of the group (an account of an entity, identified by its
 * code, or one of the synthetic group accounts of the group).
 *
 * @property string $account_code
 * @property string $account_type
 * @property string $debit
 * @property string $credit
 */
final class ConsolidationAdjustmentLine extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_adjustment_lines';

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }
}
