<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The code a reader sends for a user, whatever the reader.
 */
final class TimeBadge extends BaseEnterpriseModel
{
    protected $table = 'time_badges';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
