<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class ConsolidationElimination extends BaseEnterpriseModel
{
    protected $table = 'consolidation_eliminations';

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
