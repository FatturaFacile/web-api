<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

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
