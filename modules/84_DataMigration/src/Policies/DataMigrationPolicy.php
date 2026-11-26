<?php

declare(strict_types=1);

namespace Modules\DataMigration\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;

final class DataMigrationPolicy
{
    use ResolvesTenantMembership;

    public const string ABILITY_ACCESS = 'data-migration.access';

    public const string ABILITY_ROLLBACK = 'data-migration.rollback';

    public function access(User $user): bool
    {
        return $this->isMember($user);
    }

    public function rollback(User $user): bool
    {
        return $this->isMember($user);
    }
}
