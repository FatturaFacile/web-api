<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;

/**
 * Dedicated audit log for external fiduciary actions and blocked mutations on locked periods.
 */
final class FiduciaryAuditLog extends BaseEnterpriseModel
{
    use BelongsToCompany;

    protected $table = 'fiduciary_audit_logs';

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }
}
