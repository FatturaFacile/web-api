<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\DTOs\RoutingResult;
use Modules\CRM\Events\LeadAssignedEvent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadRoutingRule;
use Modules\CRM\Notifications\LeadAssignedNotification;
use Modules\CRM\Notifications\LeadNotificationPayload;

final readonly class LeadRoutingService implements LeadRoutingServiceInterface
{
    /**
     * Tenant membership roles notified when a lead cannot be assigned.
     */
    private const array ADMIN_ROLES = ['owner', 'admin'];

    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function route(Lead $lead, ?string $actorUserId = null): RoutingResult
    {
        $result = DB::transaction(function () use ($lead, $actorUserId): RoutingResult {
            $tenantId = (string) $lead->tenant_id;

            /** @var Collection<int, LeadRoutingRule> $rules */
            $rules = LeadRoutingRule::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('priority')
                ->orderBy('created_at')
                ->get();

            $rule = $rules->first(fn (LeadRoutingRule $candidate): bool => ! $candidate->is_default && $this->matches($lead, $candidate->conditions));
            $usedDefault = false;

            if ($rule === null) {
                $rule = $rules->first(fn (LeadRoutingRule $candidate): bool => $candidate->is_default);
                $usedDefault = $rule !== null;
            }

            $assigneeId = $rule !== null ? $this->pickAssignee($rule, $tenantId) : null;
            $previousUserId = $lead->assigned_user_id !== null ? (string) $lead->assigned_user_id : null;

            $before = $lead->toArray();
            $lead->forceFill(['assigned_user_id' => $assigneeId])->save();

            $this->auditLedger->record(
                action: 'LEAD_ASSIGNED',
                model: $lead,
                payloadBefore: $before,
                payloadAfter: [
                    'assigned_user_id' => $assigneeId,
                    'routing_rule_id' => $rule?->id,
                    'default_rule' => $usedDefault,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            $event = new LeadAssignedEvent(
                leadId: (string) $lead->id,
                assignedUserId: $assigneeId,
                previousUserId: $previousUserId,
                routingRuleId: $rule !== null ? (string) $rule->id : null,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                companyId: $tenantId,
            );
            $this->dispatcher->publish($event);
            event($event);

            return new RoutingResult($assigneeId, $rule !== null ? (string) $rule->id : null, $usedDefault);
        });

        $this->notify($lead, $result);

        return $result;
    }

    public function matches(Lead $lead, ?array $conditions): bool
    {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        $listConditions = [
            'countries' => $lead->country,
            'regions' => $lead->state_region,
            'product_lines' => $lead->product_line,
            'ratings' => $lead->rating,
        ];

        foreach ($listConditions as $key => $value) {
            $allowed = $conditions[$key] ?? null;
            if (! is_array($allowed) || $allowed === []) {
                continue;
            }

            $normalizedAllowed = array_map(static fn (mixed $item): string => mb_strtolower(trim((string) $item)), $allowed);
            if ($value === null || ! in_array(mb_strtolower(trim((string) $value)), $normalizedAllowed, true)) {
                return false;
            }
        }

        $employeeMin = $conditions['employee_min'] ?? null;
        $employeeMax = $conditions['employee_max'] ?? null;

        if ($employeeMin !== null || $employeeMax !== null) {
            if ($lead->employee_count === null) {
                return false;
            }

            $employees = (int) $lead->employee_count;
            if ($employeeMin !== null && $employees < (int) $employeeMin) {
                return false;
            }
            if ($employeeMax !== null && $employees > (int) $employeeMax) {
                return false;
            }
        }

        return true;
    }

    public function reorder(string $tenantId, array $ruleIds): void
    {
        DB::transaction(function () use ($tenantId, $ruleIds): void {
            /** @var Collection<int, LeadRoutingRule> $rules */
            $rules = LeadRoutingRule::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('priority')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $position = array_flip($ruleIds);
            $ordered = $rules->sortBy(fn (LeadRoutingRule $rule, int $index): int => isset($position[(string) $rule->id])
                ? $position[(string) $rule->id]
                : count($ruleIds) + $index)->values();

            foreach ($ordered as $index => $rule) {
                $priority = ($index + 1) * 10;
                if ((int) $rule->priority !== $priority) {
                    $rule->forceFill(['priority' => $priority])->save();
                }
            }
        });
    }

    /**
     * Picks the rule's next assignee. Round-robin advances the cursor under a row lock so concurrent
     * deliveries never hand two consecutive leads to the same rep.
     */
    private function pickAssignee(LeadRoutingRule $rule, string $tenantId): ?string
    {
        $locked = LeadRoutingRule::query()->whereKey($rule->id)->lockForUpdate()->first();
        if (! $locked instanceof LeadRoutingRule) {
            return null;
        }

        $assignees = $locked->assigneeIds();
        if ($assignees === []) {
            return null;
        }

        $eligible = $this->eligibleUserIds($assignees, $tenantId);

        if ($locked->strategy === 'fixed') {
            foreach ($assignees as $assignee) {
                if (in_array($assignee, $eligible, true)) {
                    return $assignee;
                }
            }

            return null;
        }

        $count = count($assignees);
        $cursor = (int) $locked->round_robin_cursor;

        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($cursor + $offset) % $count;
            if (in_array($assignees[$index], $eligible, true)) {
                $locked->forceFill(['round_robin_cursor' => ($index + 1) % $count])->save();

                return $assignees[$index];
            }
        }

        return null;
    }

    /**
     * Assignees that still exist and still belong to the tenant.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function eligibleUserIds(array $userIds, string $tenantId): array
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->whereHas('tenants', fn (Builder $query) => $query->where('tenants.id', $tenantId))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function notify(Lead $lead, RoutingResult $result): void
    {
        $payload = LeadNotificationPayload::fromLead($lead);

        if ($result->assignedUserId !== null) {
            User::query()->find($result->assignedUserId)?->notify(new LeadAssignedNotification($payload));

            return;
        }

        $tenantId = (string) $lead->tenant_id;
        User::query()
            ->whereHas('tenants', fn (Builder $query) => $query
                ->where('tenants.id', $tenantId)
                ->whereIn('tenant_user.role', self::ADMIN_ROLES))
            ->get()
            ->each(fn (User $admin) => $admin->notify(new LeadAssignedNotification($payload, unassigned: true)));
    }
}
