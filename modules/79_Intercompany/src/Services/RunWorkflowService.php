<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\Intercompany\Enums\RunStatus;
use Modules\Intercompany\Exceptions\IntercompanyConflictException;
use Modules\Intercompany\Models\RunWorkflow;
use Modules\Intercompany\Models\RunWorkflowEvent;
use Modules\Intercompany\Support\GroupScope;

/**
 * Guided workflow of a consolidation run: draft, calculating, review, then locked (the official run of the period) and
 * posted (published in the consolidated package). Every transition is checked against the version the client saw, so two
 * controllers cannot move the same run at once (409). A locked or posted run cannot be changed; it can only be reopened
 * with a written reason, which is kept in the audit trail together with who did it.
 */
final readonly class RunWorkflowService
{
    /**
     * @var array<string, list<string>>
     */
    private const array Transitions = [
        'draft' => ['calculating'],
        'calculating' => ['review', 'draft'],
        'review' => ['locked', 'calculating'],
        'locked' => ['posted'],
        'posted' => [],
    ];

    public function __construct(
        private GroupScope $scope,
        private ConsolidationService $consolidation,
    ) {}

    public function state(User $user, ConsolidationRun $run): RunWorkflow
    {
        $this->consolidation->assertCanReadRun($user, $run);

        return $this->scope->runAs((string) $run->tenant_id, fn (): RunWorkflow => $this->ensure($run));
    }

    /**
     * @return list<string>
     */
    public function allowed(RunWorkflow $workflow): array
    {
        return self::Transitions[$workflow->status] ?? [];
    }

    /**
     * @return Collection<int, RunWorkflowEvent>
     */
    public function history(ConsolidationRun $run): Collection
    {
        return RunWorkflowEvent::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->orderBy('created_at')->orderBy('id')->get();
    }

    public function advance(User $user, ConsolidationRun $run, RunStatus $to, int $expectedVersion): RunWorkflow
    {
        $this->consolidation->assertCanReadRun($user, $run);

        return $this->scope->runAs((string) $run->tenant_id, function () use ($user, $run, $to, $expectedVersion): RunWorkflow {
            return DB::transaction(function () use ($user, $run, $to, $expectedVersion): RunWorkflow {
                $workflow = $this->locked($run, $expectedVersion);
                $from = (string) $workflow->status;

                if (! in_array($to->value, self::Transitions[$from] ?? [], true)) {
                    throw new IntercompanyConflictException("The run cannot move from {$from} to {$to->value}.", in_array($from, ['locked', 'posted'], true) ? 'run_locked' : 'illegal_transition');
                }

                if ($to === RunStatus::Review) {
                    $verified = $this->consolidation->verify($user, $run);

                    if (! $verified['matches']) {
                        throw new IntercompanyConflictException('The ledgers, the exchange rates or the group configuration changed since this run: create a new run.', 'inputs_changed');
                    }
                }

                if ($to === RunStatus::Locked) {
                    $this->consolidation->lock($user, $run);
                }

                $action = match ($to) {
                    RunStatus::Locked => 'lock',
                    RunStatus::Posted => 'post',
                    default => 'advance',
                };

                return $this->move($workflow, $to, $action, null, $user);
            });
        });
    }

    public function reopen(User $user, ConsolidationRun $run, string $reason, int $expectedVersion): RunWorkflow
    {
        $this->consolidation->assertCanReadRun($user, $run);

        return $this->scope->runAs((string) $run->tenant_id, function () use ($user, $run, $reason, $expectedVersion): RunWorkflow {
            return DB::transaction(function () use ($user, $run, $reason, $expectedVersion): RunWorkflow {
                $workflow = $this->locked($run, $expectedVersion);

                if (! in_array($workflow->status, [RunStatus::Locked->value, RunStatus::Posted->value], true)) {
                    throw new IntercompanyConflictException('Only a locked or posted run can be reopened.', 'run_not_locked');
                }

                ConsolidationRun::query()->whereKey($run->id)->update(['status' => ConsolidationRun::StatusCompleted, 'locked_at' => null, 'locked_by' => null]);

                return $this->move($workflow, RunStatus::Review, 'reopen', $reason, $user);
            });
        });
    }

    /**
     * Refuses a change to a run that is locked or posted. Used by every writer of data that belongs to a run.
     */
    public function assertEditable(ConsolidationRun $run): void
    {
        $status = $this->scope->runAs((string) $run->tenant_id, fn (): string => (string) $this->ensure($run)->status);

        if (in_array($status, [RunStatus::Locked->value, RunStatus::Posted->value], true)) {
            throw new IntercompanyConflictException('The run is locked: reopen it with a reason before changing it.', 'run_locked');
        }
    }

    private function ensure(ConsolidationRun $run): RunWorkflow
    {
        $workflow = RunWorkflow::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->first();

        if ($workflow !== null) {
            return $workflow;
        }

        $run->refresh();

        return RunWorkflow::withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'status' => $run->isLocked() ? RunStatus::Locked->value : RunStatus::Draft->value,
            'version' => 1,
        ]);
    }

    private function locked(ConsolidationRun $run, int $expectedVersion): RunWorkflow
    {
        $this->ensure($run);
        $workflow = RunWorkflow::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->lockForUpdate()->firstOrFail();

        if ((int) $workflow->version !== $expectedVersion) {
            throw new IntercompanyConflictException('The run was changed by someone else: reload it and try again.', 'stale_version');
        }

        return $workflow;
    }

    private function move(RunWorkflow $workflow, RunStatus $to, string $action, ?string $reason, User $user): RunWorkflow
    {
        $from = (string) $workflow->status;
        $workflow->forceFill(['status' => $to->value, 'version' => $workflow->version + 1])->save();

        RunWorkflowEvent::withoutGlobalScopes()->create([
            'tenant_id' => $workflow->tenant_id,
            'run_id' => $workflow->run_id,
            'from_status' => $from,
            'to_status' => $to->value,
            'action' => $action,
            'reason' => $reason,
            'user_id' => $user->id,
        ]);

        return $workflow->refresh();
    }
}
