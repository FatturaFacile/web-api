<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the contract alert (tenant scoped where it has a tenant_id).
 */
final class ContractAlert extends BaseEnterpriseModel
{
    protected $table = 'contract_alerts';

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }
}
