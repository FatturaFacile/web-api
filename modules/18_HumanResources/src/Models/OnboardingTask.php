<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A step of an onboarding.
 */
final class OnboardingTask extends BaseEnterpriseModel
{
    protected $table = 'hr_onboarding_tasks';

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'sort_order' => 'integer', 'completed_at' => 'datetime'];
    }
}
