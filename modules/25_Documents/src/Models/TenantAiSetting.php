<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Eloquent model of the tenant ai setting (tenant scoped where it has a tenant_id).
 */
final class TenantAiSetting extends BaseEnterpriseModel
{
    protected $table = 'tenant_ai_settings';

    protected function casts(): array
    {
        return [
            'external_providers_enabled' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }
}
