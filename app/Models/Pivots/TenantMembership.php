<?php

declare(strict_types=1);

namespace App\Models\Pivots;

/**
 * The `tenant_user` row: the membership of a user in a tenant, with its role and per-module permissions.
 *
 * @property string $id
 * @property string $role
 * @property string|null $permissions JSON list of permission slugs
 * @property string|null $suspended_at
 * @property string|null $custom_role_id
 */
class TenantMembership extends AuditedPivot {}
