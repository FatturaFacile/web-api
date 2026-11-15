<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves fine-grained inventory permissions (`inventory.valuation.manage`, `inventory.book.export`)
 * through the existing tenant membership mechanism: `tenant_user.role` (owner/admin are tenant
 * admins) and `tenant_user.permissions` (JSON list supporting `*` and `prefix.*` wildcards),
 * falling back to Spatie permissions when the permission is registered there.
 */
final class InventoryPermissionChecker
{
    public const string VALUATION_MANAGE = 'inventory.valuation.manage';

    public const string BOOK_EXPORT = 'inventory.book.export';

    private const array ADMIN_ROLES = ['owner', 'admin', 'tenant_admin', 'super_admin'];

    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function currentTenantId(): ?string
    {
        return $this->tenantContext->getTenantId();
    }

    public function isMember(User $user): bool
    {
        return $this->membership($user) !== null;
    }

    public function allows(User $user, string $permission): bool
    {
        $membership = $this->membership($user);
        if ($membership === null) {
            return false;
        }

        if (in_array(strtolower((string) $membership->role), self::ADMIN_ROLES, true)) {
            return true;
        }

        foreach ($this->decodePermissions($membership->permissions ?? null) as $granted) {
            if ($this->matches($granted, $permission)) {
                return true;
            }
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function membership(User $user): ?object
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            return null;
        }

        /** @var object{role: string|null, permissions: string|null}|null $row */
        $row = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->first(['role', 'permissions']);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            // Values attached through Eloquent pivots may be double-encoded JSON strings.
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $raw = $decoded;
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_string'));
    }

    private function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) {
            return true;
        }

        if (str_ends_with($granted, '.*')) {
            return str_starts_with($permission, substr($granted, 0, -1));
        }

        return false;
    }
}
