<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Access\TenantAccessDelegation;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Temporary delegations: a member lends their role (an owner lends the admin role) to a colleague for a time window.
 * The delegate acts with the lent role next to their own, and only inside the window.
 */
final class DelegationService
{
    /**
     * The roles lent to a user right now in a tenant, with the modules they are limited to (null: all).
     *
     * @return list<array{role: string, modules: list<string>|null}>
     */
    public function activeFor(?string $userId, ?string $tenantId): array
    {
        if ($userId === null || $tenantId === null) {
            return [];
        }

        return DB::table('tenant_access_delegations')
            ->where('tenant_id', $tenantId)->where('delegate_id', $userId)
            ->whereNull('revoked_at')->whereNull('deleted_at')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->get(['role', 'modules'])
            ->map(static fn (object $row): array => ['role' => (string) $row->role, 'modules' => $row->modules === null ? null : (array) json_decode((string) $row->modules, true)])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeAll(Tenant $tenant): array
    {
        return TenantAccessDelegation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->with(['delegator:id,name,email', 'delegate:id,name,email'])
            ->orderByDesc('starts_at')->limit(200)->get()
            ->map(fn (TenantAccessDelegation $delegation): array => [
                'id' => $delegation->id,
                'delegator' => ['id' => $delegation->delegator?->id, 'name' => $delegation->delegator?->name, 'email' => $delegation->delegator?->email],
                'delegate' => ['id' => $delegation->delegate?->id, 'name' => $delegation->delegate?->name, 'email' => $delegation->delegate?->email],
                'role' => $delegation->role,
                'modules' => $delegation->modules,
                'reason' => $delegation->reason,
                'starts_at' => $delegation->starts_at->toIso8601String(),
                'ends_at' => $delegation->ends_at->toIso8601String(),
                'revoked_at' => $delegation->revoked_at?->toIso8601String(),
                'status' => $this->status($delegation),
            ])->all();
    }

    /**
     * @param  list<string>|null  $modules
     *
     * @throws DomainException when the people involved are not active members or the window is empty
     */
    public function create(Tenant $tenant, User $delegator, User $delegate, Carbon $startsAt, Carbon $endsAt, ?array $modules, ?string $reason): TenantAccessDelegation
    {
        if ($delegator->is($delegate)) {
            throw new DomainException('A member cannot delegate to themselves.');
        }

        if (! $endsAt->greaterThan($startsAt) || $endsAt->isPast()) {
            throw new DomainException('The delegation must end after it starts, in the future.');
        }

        $delegatorRole = $tenant->users()->wherePivotNull('suspended_at')->where('users.id', $delegator->id)->first()?->pivot->role;

        if ($delegatorRole === null || $tenant->users()->wherePivotNull('suspended_at')->where('users.id', $delegate->id)->doesntExist()) {
            throw new DomainException('Delegator and delegate must be active members of the tenant.');
        }

        $delegation = TenantAccessDelegation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'delegator_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'role' => $delegatorRole === 'owner' ? 'admin' : $delegatorRole,
            'modules' => $modules,
            'reason' => $reason,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeDelegation, (string) $delegation->id, 'delegation.created', null, [
            'delegator' => $delegator->email, 'delegate' => $delegate->email, 'role' => $delegation->role, 'modules' => $modules,
            'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(),
        ]);

        return $delegation;
    }

    public function revoke(Tenant $tenant, TenantAccessDelegation $delegation): void
    {
        if ($delegation->revoked_at !== null) {
            return;
        }

        $delegation->forceFill(['revoked_at' => now()])->save();

        AccessAudit::record((string) $tenant->id, AccessAudit::TypeDelegation, (string) $delegation->id, 'delegation.revoked', null, ['delegate_id' => $delegation->delegate_id]);
    }

    private function status(TenantAccessDelegation $delegation): string
    {
        return match (true) {
            $delegation->revoked_at !== null => 'revoked',
            $delegation->ends_at->isPast() => 'expired',
            $delegation->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }
}
