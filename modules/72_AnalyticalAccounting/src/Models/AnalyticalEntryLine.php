<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Record of the table analytical_entry_lines.
 */
final class AnalyticalEntryLine extends BaseEnterpriseModel
{
    protected $table = 'analytical_entry_lines';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'split_percentage' => 'decimal:4', 'posting_date' => 'date', 'derivation_meta' => 'array', 'line_number' => 'integer'];
    }
}
