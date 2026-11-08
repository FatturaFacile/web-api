<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the workspace synced item (tenant scoped where it has a tenant_id).
 */
final class WorkspaceSyncedItem extends BaseEnterpriseModel
{
    protected $table = 'workspace_synced_items';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
