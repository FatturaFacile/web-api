<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Access\TenantCustomRole;
use App\Models\Access\TenantInvitation;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * The plan of a company and what it allows: how many active people (members plus open invitations) and how many custom
 * roles. The limit of a tenant is its own override when set, otherwise the one of its plan in config/access.php; null
 * means unlimited.
 */
final class AccessPlanLimits
{
    /**
     * @return list<string>
     */
    public function planCodes(): array
    {
        return array_keys((array) config('access.plans'));
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Tenant $tenant): array
    {
        return [
            'plan_code' => $tenant->plan_code ?? 'enterprise',
            'plans' => collect((array) config('access.plans'))
                ->map(fn (array $limits, string $code): array => ['code' => $code, 'max_users' => $limits['max_users'], 'max_custom_roles' => $limits['max_custom_roles']])
                ->values()
                ->all(),
            'max_users' => $this->maxUsers($tenant),
            'active_users' => $this->activeUsers($tenant),
            'pending_invitations' => $this->pendingInvitations($tenant),
            'max_custom_roles' => $this->maxCustomRoles($tenant),
            'custom_roles' => $this->customRoles($tenant),
        ];
    }

    public function maxUsers(Tenant $tenant): ?int
    {
        return $tenant->max_users !== null ? (int) $tenant->max_users : $this->planLimit($tenant, 'max_users');
    }

    public function maxCustomRoles(Tenant $tenant): ?int
    {
        return $tenant->max_custom_roles !== null ? (int) $tenant->max_custom_roles : $this->planLimit($tenant, 'max_custom_roles');
    }

    public function activeUsers(Tenant $tenant): int
    {
        return $tenant->users()->wherePivotNull('suspended_at')->count();
    }

    public function pendingInvitations(Tenant $tenant): int
    {
        return TenantInvitation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantInvitation::Pending)
            ->where('expires_at', '>', now())
            ->count();
    }

    public function customRoles(Tenant $tenant): int
    {
        return TenantCustomRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
    }

    /**
     * @throws ValidationException when the plan has no room for that many more people
     */
    public function assertRoomForUsers(Tenant $tenant, int $additional = 1): void
    {
        $max = $this->maxUsers($tenant);

        if ($max !== null && $this->activeUsers($tenant) + $this->pendingInvitations($tenant) + $additional > $max) {
            throw ValidationException::withMessages(['users' => __('access.errors.user_limit', ['max' => $max])]);
        }
    }

    /**
     * @throws ValidationException when the plan allows no further custom role
     */
    public function assertRoomForCustomRole(Tenant $tenant): void
    {
        $max = $this->maxCustomRoles($tenant);

        if ($max !== null && $this->customRoles($tenant) >= $max) {
            throw ValidationException::withMessages(['name' => __('access.errors.role_limit', ['max' => $max])]);
        }
    }

    /**
     * @param  array{plan_code?: string, max_users?: int|null, max_custom_roles?: int|null}  $changes
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function update(Tenant $tenant, array $changes): array
    {
        $before = ['plan_code' => $tenant->plan_code, 'max_users' => $tenant->max_users, 'max_custom_roles' => $tenant->max_custom_roles];

        $tenant->forceFill(array_intersect_key($changes, $before))->save();

        return ['before' => $before, 'after' => ['plan_code' => $tenant->plan_code, 'max_users' => $tenant->max_users, 'max_custom_roles' => $tenant->max_custom_roles]];
    }

    private function planLimit(Tenant $tenant, string $key): ?int
    {
        $limit = config("access.plans.{$tenant->plan_code}.{$key}");

        return $limit === null ? null : (int) $limit;
    }
}
