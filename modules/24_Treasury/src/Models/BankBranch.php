<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class BankBranch extends BaseEnterpriseModel
{
    protected $table = 'bank_branches';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
