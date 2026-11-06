<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

/**
 * What a role of the company can do in the module, on top of the access rule of the routes. Instructors, guardians and
 * members have no role here: they work through the member area, with their own token.
 */
enum AssociationCapability: string
{
    case Read = 'associations.read';
    case Manage = 'associations.manage';
    case Finance = 'associations.finance';
    case Health = 'associations.health';
    case Network = 'associations.network';

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Read => ['owner', 'admin', 'secretary', 'accountant'],
            self::Manage, self::Health => ['owner', 'admin', 'secretary'],
            self::Finance => ['owner', 'admin', 'secretary', 'accountant'],
            self::Network => ['owner', 'admin'],
        };
    }

    /**
     * Permission names of the tenant_user pivot that open this capability for a single user.
     *
     * @return list<string>
     */
    public function grants(): array
    {
        return match ($this) {
            self::Read => ['*', 'associations.read', 'associations.write'],
            self::Manage, self::Finance => ['*', 'associations.write'],
            self::Health => ['*', 'associations.health'],
            self::Network => ['*'],
        };
    }
}
