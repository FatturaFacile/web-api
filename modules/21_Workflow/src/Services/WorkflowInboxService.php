<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * What is on a person's desk and what they decided before. The inbox holds the pending steps the person can decide: the
 * ones assigned to them, to their role in the company, and, while an out-of-office delegation is in force, the ones of
 * the colleague who delegated to them (within the scope the delegator chose). Owners and administrators also see every
 * step assigned to a role, because the engine lets them decide those.
 */
final readonly class WorkflowInboxService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Pending steps the user can decide, soonest deadline first.
     *
     * @param  array{state?: ?string, definition_code?: ?string, approvable_type?: ?string}  $filters  state: overdue, delegated or escalated
     * @return Builder<WorkflowInstanceStep>
     */
    public function tasks(User $user, array $filters = []): Builder
    {
        $role = $this->roleOf($user);
        $delegations = $this->activeDelegationsTo($user);

        return WorkflowInstanceStep::query()
            ->where('workflow_instance_steps.status', 'pending')
            ->whereHas('instance', static fn (Builder $instance) => $instance->whereIn('status', ['pending', 'in_progress']))
            ->where(function (Builder $query) use ($user, $role, $delegations): void {
                $query->where('assigned_user_id', $user->id);

                if ($role !== '') {
                    $query->orWhere('assigned_role', $role);
                }

                if (in_array($role, ['owner', 'admin'], true)) {
                    $query->orWhereNotNull('assigned_role');
                }

                foreach ($delegations as $delegation) {
                    $query->orWhere(function (Builder $delegated) use ($delegation): void {
                        $delegated->where('assigned_user_id', $delegation->delegator_user_id);

                        if ($delegation->scope !== 'all') {
                            $delegated->whereHas('instance', fn (Builder $instance) => $instance->whereIn('approvable_type', $this->typesOfModule((string) $delegation->scope)));
                        }
                    });
                }
            })
            ->when($filters['state'] ?? null, function (Builder $query, string $state) use ($user): void {
                match ($state) {
                    'overdue' => $query->whereNotNull('due_at')->where('due_at', '<', now()),
                    'escalated' => $query->whereNotNull('escalated_at'),
                    'delegated' => $query->where(fn (Builder $delegated) => $delegated->where('assigned_user_id', '!=', $user->id)->whereNotNull('assigned_user_id')),
                    default => null,
                };
            })
            ->when($filters['definition_code'] ?? null, static fn (Builder $query, string $code) => $query->whereHas('instance.definition', static fn (Builder $definition) => $definition->where('code', $code)))
            ->when($filters['approvable_type'] ?? null, static fn (Builder $query, string $type) => $query->whereHas('instance', static fn (Builder $instance) => $instance->where('approvable_type', $type)))
            ->with(['instance.definition', 'instance.approvable', 'node'])
            ->orderByRaw('due_at ASC NULLS LAST')
            ->orderBy('workflow_instance_steps.created_at');
    }

    /**
     * Counters for the badge of the inbox.
     *
     * @return array{pending: int, overdue: int, delegated: int, escalated: int}
     */
    public function summary(User $user): array
    {
        return [
            'pending' => $this->tasks($user)->count(),
            'overdue' => $this->tasks($user, ['state' => 'overdue'])->count(),
            'delegated' => $this->tasks($user, ['state' => 'delegated'])->count(),
            'escalated' => $this->tasks($user, ['state' => 'escalated'])->count(),
        ];
    }

    /**
     * Decisions the user took, own or on behalf of a colleague, newest first.
     *
     * @param  array{decision?: ?string, from?: ?string, to?: ?string}  $filters
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, ApprovalDecision>
     */
    public function history(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return ApprovalDecision::query()
            ->where(static fn (Builder $query) => $query->where('user_id', $user->id)->orWhere('effective_user_id', $user->id))
            ->when($filters['decision'] ?? null, static fn (Builder $query, string $decision) => $query->where('decision', $decision))
            ->when($filters['from'] ?? null, static fn (Builder $query, string $from) => $query->whereDate('decided_at', '>=', $from))
            ->when($filters['to'] ?? null, static fn (Builder $query, string $to) => $query->whereDate('decided_at', '<=', $to))
            ->with(['step.instance.definition', 'step.instance.approvable'])
            ->orderByDesc('decided_at')
            ->paginate($perPage);
    }

    /**
     * Instances the user started, newest first.
     *
     * @return Builder<WorkflowInstance>
     */
    public function requests(User $user, ?string $status): Builder
    {
        return WorkflowInstance::query()
            ->where('created_by', $user->id)
            ->when($status, static fn (Builder $query, string $value) => $query->where('status', $value))
            ->with('definition')
            ->orderByDesc('started_at');
    }

    private function roleOf(User $user): string
    {
        return (string) $user->getRoleInTenant((string) $this->tenantContext->getTenantId());
    }

    /**
     * @return Collection<int, WorkflowDelegation>
     */
    private function activeDelegationsTo(User $user): Collection
    {
        return WorkflowDelegation::query()
            ->where('delegate_user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get();
    }

    /**
     * Approvable classes the workflow configuration maps to a module; empty for a scope that names no module.
     *
     * @return list<string>
     */
    private function typesOfModule(string $module): array
    {
        return array_values(array_map(
            static fn (array $definition): string => $definition['class'],
            array_filter((array) config('workflow.approvables', []), static fn (array $definition): bool => ($definition['module'] ?? null) === $module)
        ));
    }
}
