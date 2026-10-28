<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class WorkflowEscalationRule extends BaseEnterpriseModel
{
    protected $table = 'workflow_escalation_rules';

    protected function casts(): array
    {
        return [
            'reminder_hours' => 'integer',
            'escalation_hours' => 'integer',
        ];
    }
}
