<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class MfgEngineeringChangeOrder extends BaseEnterpriseModel
{
    protected $table = 'mfg_engineering_change_orders';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'approved_at' => 'datetime',
            'implemented_at' => 'datetime',
            'change_payload' => 'array',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isImplemented(): bool
    {
        return $this->status === 'implemented';
    }
}
