<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class LeadRoutingRule extends BaseEnterpriseModel
{
    protected $table = 'crm_lead_routing_rules';

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'assignee_user_ids' => 'array',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'rotation_cursor' => 'integer',
        ];
    }
}
