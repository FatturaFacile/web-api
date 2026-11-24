<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the analytical cost ledger of a project (Mod 082): the committed or actual cost of a source document
 * (approved timesheet, employee expense, vendor invoice or goods receipt, warehouse issue, overhead allocation, open
 * purchase order) on a WBS node, with its base-currency value. Unique per project, source type and source document, so a
 * re-sync updates the line instead of duplicating it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $wbs_node_id
 * @property string|null $cost_center_id
 * @property string $source_type
 * @property string $source_document_id
 * @property string|null $source_document_reference
 * @property string $committed_amount
 * @property string $actual_amount
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $base_currency_committed_amount
 * @property string $base_currency_actual_amount
 * @property CarbonInterface $posting_date
 * @property bool $is_reconciled
 * @property string|null $reconciliation_ref
 */
final class ProjectCostLedgerEntry extends BaseEnterpriseModel
{
    protected $table = 'project_cost_ledger_entries';

    protected function casts(): array
    {
        return [
            'committed_amount' => 'decimal:4',
            'actual_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'base_currency_committed_amount' => 'decimal:4',
            'base_currency_actual_amount' => 'decimal:4',
            'posting_date' => 'date',
            'is_reconciled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
