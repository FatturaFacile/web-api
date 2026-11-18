<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property array{regions?: list<string>, countries?: list<string>, product_lines?: list<string>, min_size?: int|string|null, max_size?: int|string|null}|null $criteria
 * @property array<array-key, string>|null $assignee_user_ids
 */
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
