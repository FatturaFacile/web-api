<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Access\AccessDelegation;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Temporary delegations: for a period a member acts with the access of another one (every module or only some). The
 * module gate asks `delegatorAllows()` when the member has no access of their own.
 */
final class AccessDelegations
{
    public function __construct(private readonly AccessAudit $audit) {}

    /**
     * @return list<AccessDelegation>
     */
    public function list(Tenant $tenant): array
    {
        return AccessDelegation::query()->where('tenant_id', $tenant->id)->with(['delegator', 'delegate'])->orderByDesc('starts_at')->get()->all();
    }

    /**
     * @param  array{delegate_id: string, delegator_id?: string|null, starts_at: string, ends_at: string, modules?: list<string>|null, reason?: string|null}  $data
     *
     * @throws DomainException when the delegator or the delegate is not an active member, or the actor may not delegate
     */
    public function create(Tenant $tenant, User $actor, array $data): AccessDelegation
    {
        $delegatorId = $data['delegator_id'] ?? (string) $actor->id;
        $delegatorRole = $this->activeRole($tenant, $delegatorId) ?? throw new DomainException(__('access.errors.delegator_not_member'));
        $this->activeRole($tenant, $data['delegate_id']) ?? throw new DomainException(__('access.errors.delegate_not_member'));

        if ($delegatorId === $data['delegate_id']) {
            throw new DomainException(__('access.errors.delegate_is_delegator'));
        }

        $actorRole = $actor->is_platform_admin ? 'owner' : $this->activeRole($tenant, (string) $actor->id);

        if ($delegatorId !== (string) $actor->id && ! in_array($actorRole, ['owner', 'admin'], true)) {
            throw new DomainException(__('access.errors.delegate_for_others'));
        }

        if ($delegatorRole === 'owner' && $actorRole !== 'owner') {
            throw new DomainException(__('access.errors.delegate_owner_reserved'));
        }

        $modules = $data['modules'] ?? null;

        $delegation = AccessDelegation::query()->create([
            'tenant_id' => $tenant->id,
            'delegator_id' => $delegatorId,
            'delegate_id' => $data['delegate_id'],
            'role' => $delegatorRole,
            'modules' => $modules === null || $modules === [] ? null : array_values(array_unique($modules)),
            'reason' => $data['reason'] ?? null,
            'starts_at' => Carbon::parse($data['starts_at']),
            'ends_at' => Carbon::parse($data['ends_at']),
        ]);

        $this->audit->record('access.delegation.created', (string) $tenant->id, AccessDelegation::class, $delegation->id, null, [
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegation->delegate_id,
            'role' => $delegatorRole,
            'modules' => $delegation->modules,
            'starts_at' => $delegation->starts_at->toIso8601String(),
            'ends_at' => $delegation->ends_at->toIso8601String(),
        ]);

        return $delegation->load(['delegator', 'delegate']);
    }

    public function revoke(Tenant $tenant, AccessDelegation $delegation): void
    {
        if ($delegation->revoked_at === null) {
            $delegation->forceFill(['revoked_at' => now()])->save();

            $this->audit->record('access.delegation.revoked', (string) $tenant->id, AccessDelegation::class, $delegation->id, null, ['delegator_id' => $delegation->delegator_id, 'delegate_id' => $delegation->delegate_id]);
        }
    }

    /**
     * Whether an active delegation gives the user the access of its delegator to the module.
     *
     * @param  Closure(?User, ?string, string, string): bool  $delegatorCan  the access check of one user, without delegations
     */
    public function delegatorAllows(User $delegate, string $tenantId, string $module, string $access, Closure $delegatorCan): bool
    {
        $delegations = AccessDelegation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('delegate_id', $delegate->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with('delegator')
            ->get();

        foreach ($delegations as $delegation) {
            if ($delegation->modules !== null && ! in_array($module, $delegation->modules, true)) {
                continue;
            }

            if ($delegatorCan($delegation->delegator, $tenantId, $module, $access)) {
                return true;
            }
        }

        return false;
    }

    private function activeRole(Tenant $tenant, string $userId): ?string
    {
        $role = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $userId)->whereNull('suspended_at')->value('role');

        return is_string($role) ? $role : null;
    }
}
