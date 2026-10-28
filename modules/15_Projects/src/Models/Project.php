<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\Contact;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Project extends BaseEnterpriseModel
{
    protected $table = 'projects';

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'contract_value' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(ProjectPortfolio::class, 'portfolio_id');
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_project_id');
    }

    public function subProjects(): HasMany
    {
        return $this->hasMany(self::class, 'parent_project_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_user_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class, 'project_id')->orderBy('sequence_order');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class, 'project_id')->orderBy('milestone_date');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_id');
    }

    public function budget(): HasOne
    {
        return $this->hasOne(ProjectBudget::class, 'project_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProjectCostEntry::class, 'project_id');
    }

    public function financialSummary(): HasOne
    {
        return $this->hasOne(ProjectFinancialSummary::class, 'project_id');
    }

    public function changeOrders(): HasMany
    {
        return $this->hasMany(ProjectChangeOrder::class, 'project_id');
    }

    public function variationRequests(): HasMany
    {
        return $this->hasMany(ProjectVariationRequest::class, 'project_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class, 'project_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class, 'project_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ProjectIssue::class, 'project_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class, 'project_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class, 'project_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProjectStatusHistory::class, 'project_id')->orderBy('created_at', 'desc');
    }

    public function revenueRecognitions(): HasMany
    {
        return $this->hasMany(ProjectRevenueRecognition::class, 'project_id');
    }

    public function billingEvents(): HasMany
    {
        return $this->hasMany(ProjectBillingEvent::class, 'project_id');
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(ProjectAuditEntry::class, 'project_id')->orderBy('created_at', 'desc');
    }
}
