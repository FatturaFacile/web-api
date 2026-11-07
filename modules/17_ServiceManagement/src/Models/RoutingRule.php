<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A routing rule: when a new ticket matches every condition given (category, source, customer, keywords, priority), the
 * actions apply (team or agent, assignment strategy, priority, tags). Rules run by position.
 *
 * @property array{category_ids?: list<string>, sources?: list<string>, customer_ids?: list<string>, keywords?: list<string>, priorities?: list<string>}|null $conditions
 * @property array{assign_team_id?: string, assign_technician_id?: string, assignment_strategy?: string, set_priority?: string, add_tags?: list<string>} $actions
 */
final class RoutingRule extends BaseEnterpriseModel
{
    protected $table = 'esm_routing_rules';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
            'stop_processing' => 'boolean',
        ];
    }
}
