<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * @property array{regions?: list<string>, countries?: list<string>, product_lines?: list<string>, min_size?: int|string|null, max_size?: int|string|null}|null $criteria
 * @property array<string, mixed>|null $conditions
 * @property array<array-key, string>|null $assignee_user_ids
 */
final class LeadRoutingRule extends BaseEnterpriseModel
{
    public const array STRATEGIES = ['round_robin', 'fixed'];

    protected $table = 'crm_lead_routing_rules';

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'conditions' => 'array',
            'assignee_user_ids' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'rotation_cursor' => 'integer',
            'round_robin_cursor' => 'integer',
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
