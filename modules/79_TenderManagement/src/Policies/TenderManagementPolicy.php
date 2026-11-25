<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * The module gate (config/permissions.php) lets the finance roles in. Every member who passes it reads the tender pipeline,
 * the vault and the benchmarks. Owners, administrators and accountants (or members granted tender_management.manage) work on
 * notices, dossiers, envelopes, the vault and the history; converting an award into a contract and a project is reserved to the
 * same roles (or tender_management.convert).
 */
final class TenderManagementPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array ManageRoles = ['owner', 'admin', 'accountant'];

    /** @var list<string> */
    public const array ConvertRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->manage($user) && $this->isOwnRecord($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function manage(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::ManageRoles, ['tender_management.manage', 'tender_management.*']);
    }

    public function convert(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::ConvertRoles, ['tender_management.convert', 'tender_management.*']);
    }
}
