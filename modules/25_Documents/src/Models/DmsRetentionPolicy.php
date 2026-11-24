<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Retention period chosen by the company. No legal period ships with the application.
 */
final class DmsRetentionPolicy extends BaseEnterpriseModel
{
    protected $table = 'dms_retention_policies';

    protected function casts(): array
    {
        return [
            'retention_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
