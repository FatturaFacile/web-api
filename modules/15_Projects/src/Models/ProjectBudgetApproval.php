<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectBudgetApproval extends BaseEnterpriseModel
{
    protected $table = 'project_budget_approvals';

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ProjectBudget::class, 'project_budget_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectBudgetVersion::class, 'budget_version_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
