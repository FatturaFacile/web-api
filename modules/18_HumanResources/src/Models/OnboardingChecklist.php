<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The onboarding of an employee.
 */
final class OnboardingChecklist extends BaseEnterpriseModel
{
    protected $table = 'hr_onboarding_checklists';

    /**
     * @return HasMany<OnboardingTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class, 'onboarding_checklist_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
