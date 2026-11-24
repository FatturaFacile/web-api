<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * When a pending step is reminded and escalated, to whom, and what the engine decides when its time limit runs out.
 *
 * @property string $id
 * @property string $name
 * @property int $reminder_hours
 * @property int $escalation_hours
 * @property string $escalate_to_type
 * @property ?string $escalate_to_id
 * @property ?string $auto_action
 */
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
