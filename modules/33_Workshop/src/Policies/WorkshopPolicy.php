<?php

declare(strict_types=1);

namespace Modules\Workshop\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * Records of the active company only. The module.access middleware decides who reads and writes the module; issuing an
 * invoice is reserved to the roles that write the invoicing module.
 */
final class WorkshopPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    private const array BillingRoles = ['owner', 'admin', 'accountant', 'sales'];

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
        return $this->isMember($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->isMember($user) && $this->isOwnRecord($record);
    }

    /**
     * Invoices and vehicle sales are fiscal documents.
     */
    public function bill(User $user, Model $record): bool
    {
        return $this->isOwnRecord($record) && $this->hasRoleOrPermission($user, self::BillingRoles, ['invoicing.write']);
    }
}
