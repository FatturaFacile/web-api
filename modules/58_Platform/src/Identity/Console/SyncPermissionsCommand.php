<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Console;

use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Identity\Application\PermissionRegistry;
use Modules\Platform\Identity\Domain\Enums\SystemRole;
use Modules\Platform\Identity\Infrastructure\Models\Role;
use Modules\Platform\Identity\Infrastructure\Models\RolePermission;

/**
 * Writes the registered permission catalogue into the global system roles: missing permissions are added and
 * permissions no longer registered are removed. Idempotent.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Sync the permission registry into the system roles';

    public function handle(PermissionRegistry $registry): int
    {
        app(TenantContext::class)->runAsGlobal(fn () => DB::transaction(function () use ($registry): void {
            foreach (SystemRole::cases() as $systemRole) {
                $role = Role::query()->withoutGlobalScopes()->withTrashed()->firstOrNew(['tenant_id' => null, 'key' => $systemRole->value]);
                $role->forceFill(['name' => ucfirst(str_replace('_', ' ', $systemRole->value)), 'is_system' => true, 'deleted_at' => null])->save();

                $wanted = $registry->defaultsFor($systemRole);
                RolePermission::query()->withoutGlobalScopes()->where('role_id', $role->id)->whereNotIn('permission', $wanted)->delete();
                $existing = RolePermission::query()->withoutGlobalScopes()->where('role_id', $role->id)->pluck('permission')->all();

                foreach (array_diff($wanted, $existing) as $permission) {
                    $row = new RolePermission(['tenant_id' => null, 'role_id' => $role->id, 'permission' => $permission]);
                    $row->saveQuietly();
                }
            }
        }));

        $this->info(sprintf('Synced %d permissions into %d system roles.', count($registry->keys()), count(SystemRole::cases())));

        return self::SUCCESS;
    }
}
