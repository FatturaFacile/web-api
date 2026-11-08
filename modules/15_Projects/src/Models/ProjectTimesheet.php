<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Hours a person worked on a project on one day. Flow: draft, submitted, then approved or rejected; a rejected sheet
 * goes back to the author. Once approved it is locked: its labour cost is booked on the project and it can be billed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $project_task_id
 * @property string $user_id
 * @property string|null $role
 * @property CarbonInterface $work_date
 * @property string $hours_worked
 * @property string $overtime_hours
 * @property bool $is_billable
 * @property string $cost_rate
 * @property string $charge_rate
 * @property string $total_cost
 * @property string $total_charge
 * @property string $status
 * @property CarbonInterface|null $submitted_at
 * @property string|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $rejected_by
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $project_cost_entry_id
 * @property string|null $invoice_id
 * @property string|null $work_summary
 */
final class ProjectTimesheet extends BaseEnterpriseModel
{
    /** Statuses the author can still change. */
    public const array EditableStatuses = ['draft', 'rejected'];

    /** Statuses that still wait for a decision: a project cannot be closed while any exists. */
    public const array OpenStatuses = ['draft', 'submitted', 'rejected'];

    protected $table = 'project_timesheets';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'is_billable' => 'boolean',
            'cost_rate' => 'decimal:2',
            'charge_rate' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_charge' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EditableStatuses, true);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The draft or issued invoice that bills these hours (time and materials).
     *
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
