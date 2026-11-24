<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A manual bottom-up estimate to complete (ETC) set by the project manager on the project or on a WBS node. While it
 * exists the EAC is AC + ETC; removing it restores the formula-driven BAC / CPI.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string|null $wbs_node_id
 * @property string $estimate_to_complete
 * @property string|null $reason
 * @property string|null $set_by
 */
final class ProjectEtcOverride extends BaseEnterpriseModel
{
    protected $table = 'project_etc_overrides';

    protected function casts(): array
    {
        return ['estimate_to_complete' => 'decimal:4'];
    }
}
