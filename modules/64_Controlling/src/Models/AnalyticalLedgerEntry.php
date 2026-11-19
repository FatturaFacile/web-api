<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Controlling record of the table analytical_ledger_entries.
 *
 * @property Carbon $posting_date
 * @property int $fiscal_year
 * @property int $fiscal_month
 * @property string $amount
 * @property string $quantity
 * @property string $nature_type
 * @property string $source_type
 * @property string|null $cost_center_id
 * @property string|null $job_id
 * @property string|null $description
 */
final class AnalyticalLedgerEntry extends BaseEnterpriseModel
{
    protected $table = 'analytical_ledger_entries';

    public const array SourceTypes = ['sales_invoice', 'credit_note', 'purchase_invoice', 'payroll', 'timesheet', 'inventory_issue', 'manual_analytical', 'overhead_allocation'];

    public const array Natures = ['revenue', 'direct_material', 'direct_labor', 'variable_overhead', 'fixed_overhead', 'opex'];

    protected function casts(): array
    {
        return ['posting_date' => 'date', 'is_allocated' => 'boolean', 'fiscal_year' => 'integer', 'fiscal_month' => 'integer'];
    }
}
