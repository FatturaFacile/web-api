<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The revaluation of the open receivables or payables of one currency on one control account at a date: their book
 * value at the historical rates against their value at the closing rate, and the entry of the difference.
 */
final class FxRevaluation extends BaseEnterpriseModel
{
    protected $table = 'gl_fx_revaluations';

    protected function casts(): array
    {
        return [
            'revaluation_date' => 'date',
            'reversal_date' => 'date',
            'closing_rate' => 'decimal:8',
            'open_amount' => 'decimal:4',
            'book_value' => 'decimal:4',
            'revalued_value' => 'decimal:4',
            'difference' => 'decimal:4',
            'open_items' => 'integer',
        ];
    }
}
