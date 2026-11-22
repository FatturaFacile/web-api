<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A work centre (Mod 061) that can take over the operations of another one, in order of priority.
 *
 * @property string $work_center_id
 * @property string $alternative_work_center_id
 * @property int $priority
 */
final class MrpWorkCenterAlternative extends BaseEnterpriseModel
{
    protected $table = 'mrp_work_center_alternatives';

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
        ];
    }
}
