<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

/**
 * The fees of a child for a month, net of the subsidy, and the invoice that bills them.
 *
 * @property string $child_id
 * @property string $period
 * @property int $attended_days
 * @property string $gross_amount
 * @property string $subsidy_amount
 * @property string $net_amount
 * @property ?string $subsidy_rule_id
 * @property ?string $invoice_id
 */
final class MonthlyBill extends ChildcareRecord
{
    protected $table = 'cc_monthly_bills';

    protected function casts(): array
    {
        return ['attended_days' => 'integer', 'meals' => 'integer', 'gross_amount' => 'decimal:2', 'subsidy_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];
    }
}
