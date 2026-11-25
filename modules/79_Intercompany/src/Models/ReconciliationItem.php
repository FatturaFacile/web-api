<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A document of entity A compared with the document of entity B it should be mirrored by.
 *
 * @property Carbon|null $a_document_date
 * @property Carbon|null $b_document_date
 * @property string|null $a_amount
 * @property string|null $a_tax
 * @property string|null $b_amount
 * @property string|null $b_tax
 * @property string $difference
 * @property string $difference_group_currency
 * @property string|null $adjustment_amount
 * @property Carbon|null $resolved_at
 */
final class ReconciliationItem extends BaseEnterpriseModel
{
    protected $table = 'ic_reconciliation_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'a_document_date' => 'date',
            'b_document_date' => 'date',
            'a_amount' => 'decimal:4',
            'a_tax' => 'decimal:4',
            'b_amount' => 'decimal:4',
            'b_tax' => 'decimal:4',
            'difference' => 'decimal:4',
            'difference_group_currency' => 'decimal:4',
            'adjustment_amount' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }
}
