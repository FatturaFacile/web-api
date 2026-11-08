<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A project (commessa): the customer contract or internal initiative that collects tasks, time, costs, billing and the
 * revenue recognised over its life.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $portfolio_id
 * @property string|null $parent_project_id
 * @property string $contact_id
 * @property string $project_number
 * @property string $name
 * @property string|null $description
 * @property string $project_type
 * @property string $status
 * @property string $billing_method
 * @property string|null $revenue_recognition_method
 * @property CarbonInterface|null $planned_start_date
 * @property CarbonInterface|null $planned_end_date
 * @property CarbonInterface|null $actual_start_date
 * @property CarbonInterface|null $actual_end_date
 * @property CarbonInterface|null $baseline_start_date
 * @property CarbonInterface|null $baseline_end_date
 * @property CarbonInterface|null $baselined_at
 * @property CarbonInterface|null $closed_at
 * @property string|null $closed_by
 * @property string $project_manager_user_id
 * @property string $currency
 * @property string $contract_value
 * @property string $progress_percentage
 * @property string $health_status
 * @property string|null $created_by
 */
final class Project extends BaseEnterpriseModel
{
    /** Statuses in which the project no longer accepts time, costs or billing. */
    public const array FinalStatuses = ['closed', 'cancelled'];

    /** Revenue recognition methods the module implements (see ProjectRevenueRecognitionService). */
    public const array RecognitionMethods = ['percentage_of_completion', 'completed_contract'];

    protected $table = 'projects';

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'baseline_start_date' => 'date',
            'baseline_end_date' => 'date',
            'baselined_at' => 'datetime',
            'closed_at' => 'datetime',
            'contract_value' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
        ];
    }

    /**
     * Whether time, costs, billing and schedule changes are still accepted.
     */
    public function isOpen(): bool
    {
        return ! in_array($this->status, self::FinalStatuses, true);
    }

    /**
     * @return BelongsTo<ProjectPortfolio, $this>
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(ProjectPortfolio::class, 'portfolio_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_project_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function subProjects(): HasMany
    {
        return $this->hasMany(self::class, 'parent_project_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_user_id');
    }

    /**
     * @return HasMany<ProjectPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class, 'project_id')->orderBy('sequence_order');
    }

    /**
     * @return HasMany<ProjectMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class, 'project_id')->orderBy('milestone_date');
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_id');
    }

    /**
     * @return HasOne<ProjectBudget, $this>
     */
    public function budget(): HasOne
    {
        return $this->hasOne(ProjectBudget::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectCostEntry, $this>
     */
    public function costEntries(): HasMany
    {
        return $this->hasMany(ProjectCostEntry::class, 'project_id');
    }

    /**
     * @return HasOne<ProjectFinancialSummary, $this>
     */
    public function financialSummary(): HasOne
    {
        return $this->hasOne(ProjectFinancialSummary::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectChangeOrder, $this>
     */
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ProjectChangeOrder::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectVariationRequest, $this>
     */
    public function variationRequests(): HasMany
    {
        return $this->hasMany(ProjectVariationRequest::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectTimesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectRisk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProjectStatusHistory::class, 'project_id')->orderBy('created_at', 'desc');
    }

    /**
     * @return HasMany<ProjectRevenueRecognition, $this>
     */
    public function revenueRecognitions(): HasMany
    {
        return $this->hasMany(ProjectRevenueRecognition::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectBillingEvent, $this>
     */
    public function billingEvents(): HasMany
    {
        return $this->hasMany(ProjectBillingEvent::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectEvmSnapshot, $this>
     */
    public function evmSnapshots(): HasMany
    {
        return $this->hasMany(ProjectEvmSnapshot::class, 'project_id')->orderBy('snapshot_date', 'desc');
    }

    /**
     * @return HasMany<ProjectAuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(ProjectAuditEntry::class, 'project_id')->orderBy('created_at', 'desc');
    }
}
