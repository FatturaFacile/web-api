<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class ContractRenewal extends BaseEnterpriseModel
{
    protected $table = 'contract_renewals';

    protected function casts(): array
    {
        return [
            'previous_end_date' => 'date',
            'new_end_date' => 'date',
        ];
    }
}
