<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A punch received from a reader: its identity makes the import safe to repeat.
 */
final class TimePunchImport extends BaseEnterpriseModel
{
    protected $table = 'time_punch_imports';

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }
}
