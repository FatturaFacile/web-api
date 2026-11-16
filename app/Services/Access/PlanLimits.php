<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Access\TenantInvitation;
use App\Models\Access\TenantRole;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Users and custom roles a tenant may have, from its subscription plan or from a per-tenant override.
 */
final class PlanLimits
{
    public function maxUsers(Tenant $tenant): ?int
    {
        return $tenant->max_users ?? $this->plan($tenant)['max_users'] ?? null;
    }

    public function maxCustomRoles(Tenant $tenant): ?int
    {
        return $tenant->max_custom_roles ?? $this->plan($tenant)['max_custom_roles'] ?? null;
    }

    public function activeMembers(Tenant $tenant): int
    {
        return DB::table('tenant_user')->where('tenant_id', $tenant->id)->whereNull('suspended_at')->count();
    }

    public function pendingInvitations(Tenant $tenant): int
    {
        return TenantInvitation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    public function customRoles(Tenant $tenant): int
    {
        return TenantRole::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
    }

    /**
     * @param  int  $additional  seats the operation is about to use
     *
     * @throws PlanLimitExceededException
     */
    public function assertCanAddUsers(Tenant $tenant, int $additional = 1, bool $countInvitations = true): void
    {
        $max = $this->maxUsers($tenant);

        if ($max !== null && $this->activeMembers($tenant) + ($countInvitations ? $this->pendingInvitations($tenant) : 0) + $additional > $max) {
            throw new PlanLimitExceededException('users', $max);
        }
    }

    /**
     * @throws PlanLimitExceededException
     */
    public function assertCanAddCustomRole(Tenant $tenant): void
    {
        $max = $this->maxCustomRoles($tenant);

        if ($max !== null && $this->customRoles($tenant) + 1 > $max) {
            throw new PlanLimitExceededException('custom roles', $max);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(Tenant $tenant): array
    {
        return [
            'plan_code' => $tenant->plan_code,
            'plans' => config('access_plans.plans'),
            'max_users' => $this->maxUsers($tenant),
            'active_users' => $this->activeMembers($tenant),
            'pending_invitations' => $this->pendingInvitations($tenant),
            'max_custom_roles' => $this->maxCustomRoles($tenant),
            'custom_roles' => $this->customRoles($tenant),
        ];
    }

    /**
     * @return array{max_users: int|null, max_custom_roles: int|null}
     */
    private function plan(Tenant $tenant): array
    {
        $plans = (array) config('access_plans.plans');

        return $plans[$tenant->plan_code] ?? $plans[config('access_plans.default')] ?? ['max_users' => null, 'max_custom_roles' => null];
    }
}
