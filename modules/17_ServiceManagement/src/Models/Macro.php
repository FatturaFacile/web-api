<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ServiceManagement\Models\Concerns\SharedOrPersonal;

/**
 * A macro: field changes and an optional reply applied to a ticket in one action.
 *
 * @property array{set_status?: string, set_priority?: string, assign_team_id?: string, assign_technician_id?: string, add_tags?: list<string>, remove_tags?: list<string>, reply?: array{body: string, visibility?: string}} $actions
 */
final class Macro extends BaseEnterpriseModel
{
    use SharedOrPersonal;

    protected $table = 'esm_macros';

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'actions' => 'array',
        ];
    }
}
