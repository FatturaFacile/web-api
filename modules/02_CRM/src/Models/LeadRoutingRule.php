<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class LeadRoutingRule extends BaseEnterpriseModel
{
    public const array STRATEGIES = ['round_robin', 'fixed'];

    protected $table = 'crm_lead_routing_rules';

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'conditions' => 'array',
            'assignee_user_ids' => 'array',
            'round_robin_cursor' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function assigneeIds(): array
    {
        $ids = is_array($this->assignee_user_ids) ? $this->assignee_user_ids : [];

        return array_values(array_filter(array_map(strval(...), $ids), static fn (string $id): bool => $id !== ''));
    }
}
