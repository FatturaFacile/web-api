<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Audit\Enums\AuditEventType;

/**
 * Membership of a user in a tenant: the role and the permission list.
 *
 * @property string $id
 * @property string $role
 * @property string|null $permissions JSON list of permission slugs
 * @property string|null $suspended_at
 * @property string|null $custom_role_id
 */
class TenantMembership extends AuditedPivot implements AuditableEntityInterface
{
    use HasImmutableAuditTrail;

    /**
     * A change of the role, of the permissions or of the custom role is a permission change of the audit trail.
     *
     * @param  list<string>  $changedFields
     */
    public function auditEventTypeFor(AuditEventType $default, array $changedFields): AuditEventType
    {
        if ($default === AuditEventType::Updated && array_intersect($changedFields, ['role', 'permissions', 'custom_role_id']) !== []) {
            return AuditEventType::PermissionChanged;
        }

        return $default;
    }
}
