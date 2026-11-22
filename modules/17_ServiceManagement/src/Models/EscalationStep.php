<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of an escalation chain.
 *
 * @property string $chain_id
 * @property string $target response, next_update or resolution
 * @property int $threshold_percent
 * @property string $action notify, reassign_team or raise_priority
 * @property list<string>|null $notify_user_ids
 * @property bool $notify_team_lead
 * @property string|null $team_id
 * @property string|null $priority
 */
final class EscalationStep extends BaseEnterpriseModel
{
    public const array Targets = ['response', 'next_update', 'resolution'];

    public const array Actions = ['notify', 'reassign_team', 'raise_priority'];

    protected $table = 'esm_escalation_steps';

    protected function casts(): array
    {
        return [
            'threshold_percent' => 'integer',
            'position' => 'integer',
            'notify_user_ids' => 'array',
            'notify_team_lead' => 'boolean',
        ];
    }
}
