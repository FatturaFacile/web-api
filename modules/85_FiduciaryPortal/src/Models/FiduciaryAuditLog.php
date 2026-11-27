<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Dedicated audit log for external fiduciary actions and blocked mutations on locked periods.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $actor_user_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $target_reference
 * @property string $summary
 * @property array<string, mixed>|null $metadata_json
 * @property string|null $ip_address
 * @property Carbon|null $created_at
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
