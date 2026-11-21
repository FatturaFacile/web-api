<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Services;

use Modules\Platform\Identity\Domain\Enums\SystemRole;
use Modules\Platform\Identity\Domain\Exceptions\PrivilegeEscalationException;

final class PrivilegeEscalationGuard
{
    /**
     * @param  list<SystemRole>  $actorRoles
     * @param  list<SystemRole>  $newRoles  roles the target user would hold after the change
     * @param  list<SystemRole>  $currentTargetRoles
     * @param  int  $otherOwnersInTenant  owners of the tenant besides the target user
     */
    public function assertCanAssign(
        string $actorId,
        string $targetId,
        array $actorRoles,
        array $newRoles,
        array $currentTargetRoles,
        int $otherOwnersInTenant,
    ): void {
        if ($actorId === $targetId) {
            throw new PrivilegeEscalationException('You cannot modify your own roles.');
        }

        $actorRank = $actorRoles === []
            ? 0
            : max(array_map(static fn (SystemRole $r): int => $r->rank(), $actorRoles));

        foreach ($newRoles as $role) {
            if ($role->rank() > $actorRank) {
                throw new PrivilegeEscalationException("You cannot assign the role [{$role->value}].");
            }
        }

        $wasOwner = in_array(SystemRole::Owner, $currentTargetRoles, true);
        $remainsOwner = in_array(SystemRole::Owner, $newRoles, true);

        if ($wasOwner && ! $remainsOwner && $otherOwnersInTenant === 0) {
            throw new PrivilegeEscalationException('The last owner of a tenant cannot be removed.', 409);
        }
    }
}
