<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The onboarding of an employee, or (kind offboarding) the checklist of the tasks to close when the employee leaves.
 *
 * @property string $kind
 */
final class OnboardingChecklist extends BaseEnterpriseModel
{
    public const string KindOnboarding = 'onboarding';

    public const string KindOffboarding = 'offboarding';

    protected $table = 'hr_onboarding_checklists';

    /**
     * @return HasMany<OnboardingTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'onboarding_checklist_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'reference_date' => 'date:Y-m-d'];
    }
}
