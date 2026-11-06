<?php

declare(strict_types=1);

namespace Modules\Associations\Policies;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Modules\Associations\Models\AssociationRecord;
use Modules\Associations\Services\AssociationAccess;
use Modules\Associations\Support\AssociationCapability;

/**
 * Access to a record of the module: a record of another company is never accessible, and generic rows can be read but
 * not changed. Roles and permissions are those of the active tenant.
 */
class AssociationRecordPolicy
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AssociationAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, AssociationCapability::Read);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->access->allows($user, AssociationCapability::Read) && $this->isVisible($record);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, AssociationCapability::Manage);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->access->allows($user, AssociationCapability::Manage) && $this->isOwn($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->access->allows($user, AssociationCapability::Manage) && $this->isOwn($record);
    }

    private function isOwn(Model $record): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && (string) $record->getAttribute('tenant_id') === (string) $tenantId;
    }

    private function isVisible(Model $record): bool
    {
        return ($record instanceof AssociationRecord && $record->isGeneric()) || $this->isOwn($record);
    }
}
