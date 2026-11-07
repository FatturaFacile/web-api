<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A list of tasks for new employees: each with a title and the days after the start it is due.
 *
 * @property list<array{title: string, due_after_days?: int}> $tasks
 */
final class OnboardingTemplate extends BaseEnterpriseModel
{
    protected $table = 'hr_onboarding_templates';

    protected function casts(): array
    {
        return ['tasks' => 'array'];
    }
}
