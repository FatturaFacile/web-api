<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class TreasuryAlert extends BaseEnterpriseModel
{
    protected $table = 'treasury_alerts';

    protected function casts(): array
    {
        return [
            'threshold_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'is_acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }
}
