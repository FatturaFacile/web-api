<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use App\Exceptions\SodConflictException;
use App\Services\Tenancy\SodAssignmentGuard;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovSodException;
use Modules\Governance\Models\GovSodRule;
use Modules\Governance\Models\GovSodViolation;

/**
 * Segregation of duties: finds the conflicts of a membership, refuses blocking ones at assignment time, keeps the
 * register of violations and manages the exceptions. An exception is approved by a second person, expires and can be
 * revoked; once it expires the violation opens again at the next scan.
 */
final readonly class SodService implements SodAssignmentGuard
{
    public function __construct(
        private SodDuties $duties,
        private TenantMembershipService $memberships,
        private GovernanceLedger $ledger,
    ) {}

    /**
     * Adopts the catalog rules the company does not have yet.
     *
     * @return array{created: int, skipped: int}
     */
    public function seedDefaults(string $tenantId): array
    {
        $existing = GovSodRule::query()->where('tenant_id', $tenantId)->pluck('code')->all();
        $created = 0;

        foreach (SodRuleCatalog::all() as $definition) {
            if (in_array($definition['code'], $existing, true)) {
                continue;
            }

            GovSodRule::create($definition + ['tenant_id' => $tenantId, 'is_active' => true]);
            $created++;
        }

        return ['created' => $created, 'skipped' => count(SodRuleCatalog::all()) - $created];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(string $tenantId, array $data): GovSodRule
    {
        $rule = GovSodRule::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'duties_a' => array_values($data['duties_a']),
            'duties_b' => array_values($data['duties_b']),
            'severity' => $data['severity'] ?? 'warn',
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->ledger->record('governance.sod_rule.created', $rule, ['severity' => $rule->severity]);

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(GovSodRule $rule, array $data): GovSodRule
    {
        $rule->fill(array_intersect_key($data, array_flip(['title', 'description', 'duties_a', 'duties_b', 'severity', 'is_active'])))->save();
        $this->ledger->record('governance.sod_rule.updated', $rule, ['severity' => $rule->severity]);

        return $rule;
    }

    /**
     * The conflicts a role with the given permissions would hold under the active rules, whether or not an exception
     * covers them. Owners and administrators are never checked.
     *
     * @param  list<string>  $permissions
     * @return list<array{rule: GovSodRule, duty_a: string, duty_b: string}>
     */
    public function conflictsOf(string $tenantId, string $role, array $permissions): array
    {
        if (in_array($role, SodDuties::ExemptRoles, true)) {
            return [];
        }

        $held = $this->duties->of($role, $permissions);
        $conflicts = [];

        foreach (GovSodRule::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('code')->get() as $rule) {
            $dutyA = $this->firstHeld($held, $rule->duties_a);
            $dutyB = $dutyA === null ? null : $this->firstHeld($held, $rule->duties_b, $dutyA);

            if ($dutyA !== null && $dutyB !== null) {
                $conflicts[] = ['rule' => $rule, 'duty_a' => $dutyA, 'duty_b' => $dutyB];
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<string>  $permissions
     *
     * @throws SodConflictException
     */
    public function review(string $tenantId, string $userId, string $role, array $permissions): void
    {
        $conflicts = $this->conflictsOf($tenantId, $role, $permissions);
        $blocking = [];

        foreach ($conflicts as $conflict) {
            if ($conflict['rule']->severity === 'block' && $this->activeException($tenantId, $userId, (string) $conflict['rule']->id) === null) {
                $blocking[] = ['rule_code' => $conflict['rule']->code, 'rule_title' => $conflict['rule']->title, 'duty_a' => $conflict['duty_a'], 'duty_b' => $conflict['duty_b']];
            }
        }

        if ($blocking !== []) {
            throw new SodConflictException($blocking);
        }

        $this->syncRegister($tenantId, $userId, $conflicts);
    }

    /**
     * Compares every active membership of the company with the rules and updates the register: new conflicts open a
     * violation, conflicts covered by an active exception are marked excepted, conflicts that are gone are resolved.
     *
     * @return array{members: int, open: int, excepted: int, resolved: int}
     */
    public function scan(string $tenantId): array
    {
        $members = DB::table('tenant_user')->where('tenant_id', $tenantId)->whereNull('suspended_at')->get(['user_id', 'role', 'permissions']);

        foreach ($members as $member) {
            $permissions = $this->memberships->permissionsOf($member->permissions);
            $this->syncRegister($tenantId, (string) $member->user_id, $this->conflictsOf($tenantId, (string) $member->role, $permissions));
        }

        $activeIds = $members->pluck('user_id')->map(static fn ($id): string => (string) $id)->all();
        GovSodViolation::query()->where('tenant_id', $tenantId)->where('status', '!=', 'resolved')->whereNotIn('user_id', $activeIds)
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'exception_id' => null]);

        $count = static fn (string $status): int => GovSodViolation::query()->where('tenant_id', $tenantId)->where('status', $status)->count();

        return ['members' => $members->count(), 'open' => $count('open'), 'excepted' => $count('excepted'), 'resolved' => $count('resolved')];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function grantException(string $tenantId, string $approverId, array $data): GovSodException
    {
        if ($approverId === (string) $data['user_id']) {
            throw ValidationException::withMessages(['user_id' => ['The approver of an exception cannot be the member it is granted to.']]);
        }

        $exception = GovSodException::create([
            'tenant_id' => $tenantId,
            'rule_id' => $data['rule_id'],
            'user_id' => $data['user_id'],
            'justification' => $data['justification'],
            'approved_by' => $approverId,
            'expires_at' => $data['expires_at'],
            'status' => 'active',
        ]);
        $this->ledger->record('governance.sod_exception.granted', $exception, ['rule_id' => $exception->rule_id, 'user_id' => $exception->user_id, 'expires_at' => $exception->expires_at->toDateString()]);

        return $exception;
    }

    public function revokeException(GovSodException $exception): GovSodException
    {
        $exception->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->ledger->record('governance.sod_exception.revoked', $exception, ['rule_id' => $exception->rule_id, 'user_id' => $exception->user_id]);

        return $exception;
    }

    public function activeException(string $tenantId, string $userId, string $ruleId): ?GovSodException
    {
        return GovSodException::query()
            ->where('tenant_id', $tenantId)->where('user_id', $userId)->where('rule_id', $ruleId)
            ->where('status', 'active')->whereNull('revoked_at')->whereDate('expires_at', '>=', now()->toDateString())
            ->orderByDesc('expires_at')->first();
    }

    /**
     * @param  array{duties: list<string>, patterns: list<string>, denied: list<string>}  $held
     * @param  list<string>  $candidates
     */
    private function firstHeld(array $held, array $candidates, ?string $except = null): ?string
    {
        foreach ($candidates as $duty) {
            if ($duty !== $except && $this->duties->holds($held, $duty)) {
                return $duty;
            }
        }

        return null;
    }

    /**
     * @param  list<array{rule: GovSodRule, duty_a: string, duty_b: string}>  $conflicts
     */
    private function syncRegister(string $tenantId, string $userId, array $conflicts): void
    {
        $seen = [];

        foreach ($conflicts as $conflict) {
            $ruleId = (string) $conflict['rule']->id;
            $seen[] = $ruleId;
            $exception = $this->activeException($tenantId, $userId, $ruleId);
            $status = $exception !== null ? 'excepted' : 'open';

            $violation = GovSodViolation::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('rule_id', $ruleId)->where('status', '!=', 'resolved')->first();

            if ($violation === null) {
                $violation = GovSodViolation::create([
                    'tenant_id' => $tenantId, 'rule_id' => $ruleId, 'user_id' => $userId,
                    'duty_a' => $conflict['duty_a'], 'duty_b' => $conflict['duty_b'],
                    'status' => $status, 'detected_at' => now(), 'exception_id' => $exception?->id,
                ]);
                $this->ledger->record('governance.sod_violation.detected', $violation, ['rule_id' => $ruleId, 'user_id' => $userId, 'status' => $status]);

                continue;
            }

            if ($violation->status !== $status || $violation->exception_id !== $exception?->id) {
                $violation->forceFill(['status' => $status, 'exception_id' => $exception?->id, 'duty_a' => $conflict['duty_a'], 'duty_b' => $conflict['duty_b']])->save();
            }
        }

        GovSodViolation::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', '!=', 'resolved')
            ->whereNotIn('rule_id', $seen)->update(['status' => 'resolved', 'resolved_at' => now(), 'exception_id' => null]);
    }
}
