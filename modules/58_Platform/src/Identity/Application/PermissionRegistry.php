<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use Modules\Platform\Identity\Domain\Enums\SystemRole;
use Modules\Platform\Identity\Domain\ValueObjects\PermissionKey;

/**
 * Catalogue of the permission keys each module declares, with the system roles that hold them by default. Modules call
 * register() from their service provider; `permissions:sync` writes the defaults into the system roles. Owner and admin
 * receive every registered key.
 */
final class PermissionRegistry
{
    /** @var array<string, list<string>> permission key => default system role keys */
    private array $permissions = [];

    /**
     * @param  array<string, list<SystemRole>>  $permissions
     */
    public function register(array $permissions): void
    {
        foreach ($permissions as $key => $roles) {
            PermissionKey::from($key);
            $this->permissions[$key] = array_values(array_unique([
                ...($this->permissions[$key] ?? []),
                ...array_map(static fn (SystemRole $r): string => $r->value, $roles),
            ]));
        }
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->permissions);
        sort($keys);

        return $keys;
    }

    /**
     * Keys grouped by module then resource, for the role matrix.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->keys() as $key) {
            $parsed = PermissionKey::from($key);
            $catalog[$parsed->module][$parsed->resource][] = $parsed->action;
        }

        return $catalog;
    }

    /**
     * Default permissions of a system role: owner and admin hold everything.
     *
     * @return list<string>
     */
    public function defaultsFor(SystemRole $role): array
    {
        if ($role === SystemRole::Owner || $role === SystemRole::Admin) {
            return $this->keys();
        }

        $granted = array_keys(array_filter($this->permissions, static fn (array $roles): bool => in_array($role->value, $roles, true)));
        sort($granted);

        return $granted;
    }
}
