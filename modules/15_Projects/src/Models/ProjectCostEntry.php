<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * One cost booked on a project (job costing): labour from approved timesheets, material issued from stock, supplier
 * receipts, project expenses or manual entries. It is the analytical record of the commessa; the cost by nature is
 * booked in the ledger by its source document. Only approved entries count in the actual cost.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $project_phase_id
 * @property string|null $project_task_id
 * @property string $cost_source_type
 * @property string $cost_source_id
 * @property string $cost_category
 * @property string|null $description
 * @property string $quantity
 * @property string $uom
 * @property string $unit_cost
 * @property string $total_cost
 * @property bool $is_billable
 * @property bool $is_invoiced
 * @property string|null $invoice_id
 * @property CarbonInterface $incurred_date
 * @property string $approval_status
 * @property string|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $rejection_reason
 */
final class ProjectCostEntry extends BaseEnterpriseModel
{
    /** Cost categories, aligned with the category columns of the budget. */
    public const array Categories = ['labour', 'material', 'supplier', 'fleet', 'expense', 'equipment'];

    protected $table = 'project_cost_entries';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_invoiced' => 'boolean',
            'incurred_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Entries that count in the actual cost of the project.
     *
     * @param  Builder<ProjectCostEntry>  $query
     * @return Builder<ProjectCostEntry>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    /**
     * @return HasMany<ProjectCostAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ProjectCostAllocation::class, 'project_cost_entry_id');
    }

    /**
     * The invoice that recharges this cost to the customer (time and materials).
     *
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function costSource(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'cost_source_type', 'cost_source_id');
    }
}
