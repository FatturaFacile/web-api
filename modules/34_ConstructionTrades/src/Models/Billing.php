<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Models;

/**
 * A partial or final billing of a bid, issued as an invoice through the invoicing flow.
 *
 * @property string $kind
 * @property int $sequence
 * @property string $gross_this
 * @property string $retention_amount
 * @property string $amount_invoiced
 */
final class Billing extends TradesRecord
{
    protected $table = 'ct_billings';

    protected function casts(): array
    {
        return [
            'billing_date' => 'date', 'sequence' => 'integer', 'percentage' => 'decimal:4', 'gross_to_date' => 'decimal:2', 'previous_gross' => 'decimal:2',
            'gross_this' => 'decimal:2', 'retention_amount' => 'decimal:2', 'amount_invoiced' => 'decimal:2',
        ];
    }
}
