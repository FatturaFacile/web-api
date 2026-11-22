<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Carbon\CarbonImmutable;
use Closure;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\SuspensionReason;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Events\MesPhaseTransitionedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Commands\PhaseCommand;
use Modules\Manufacturing\Models\MfgMesCommandReceipt;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesTimeLog;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;

/**
 * Shared machinery of the MES command handlers: one transaction per command, idempotency through receipts, optimistic
 * concurrency on the phase version, pessimistic locks in a fixed order (operation, then phase) and the interval
 * bookkeeping that keeps at most one interval of a phase open.
 */
abstract class PhaseCommandHandler
{
    public function __construct(protected readonly EventDispatcher $dispatcher) {}

    /**
     * Runs the work of a command once: a retry with the same idempotency key returns the phase as it is.
     *
     * @param  Closure(): MfgMesPhase  $work
     */
    protected function execute(PhaseCommand $command, Closure $work): MfgMesPhase
    {
        $replayed = $this->replay($command);
        if ($replayed instanceof MfgMesPhase) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($command, $work): MfgMesPhase {
                $phase = $work();
                MfgMesCommandReceipt::create([
                    'tenant_id' => $command->tenantId,
                    'idempotency_key' => $command->idempotencyKey,
                    'command' => $command->name(),
                    'phase_id' => $phase->id,
                ]);

                return $phase->refresh();
            });
        } catch (UniqueConstraintViolationException $violation) {
            $replayed = $this->replay($command);
            if ($replayed instanceof MfgMesPhase) {
                return $replayed;
            }

            throw $violation;
        }
    }

    /**
     * The phase a previous run of the same command produced, null when the key is new; a key reused by another command
     * is refused.
     */
    private function replay(PhaseCommand $command): ?MfgMesPhase
    {
        $receipt = MfgMesCommandReceipt::query()->where('tenant_id', $command->tenantId)->where('idempotency_key', $command->idempotencyKey)->first();
        if ($receipt === null) {
            return null;
        }
        if ($receipt->command !== $command->name() || $receipt->phase_id === null) {
            throw new ManufacturingOperationException('The idempotency key was already used by another command.', 'idempotency_key');
        }

        return MfgMesPhase::query()->where('tenant_id', $command->tenantId)->findOrFail($receipt->phase_id);
    }

    /**
     * The phase of the company locked for update, after its operation, refused when the client's version is stale.
     */
    protected function lockPhase(PhaseCommand $command, string $phaseId): MfgMesPhase
    {
        $known = MfgMesPhase::query()->where('tenant_id', $command->tenantId)->findOrFail($phaseId);
        MfgProductionOrderOperation::query()->where('tenant_id', $command->tenantId)->whereKey($known->production_order_operation_id)->lockForUpdate()->firstOrFail();
        $phase = MfgMesPhase::query()->where('tenant_id', $command->tenantId)->whereKey($phaseId)->lockForUpdate()->firstOrFail();

        if ($command->expectedVersion !== null && $command->expectedVersion !== $phase->version) {
            throw new ManufacturingOperationException("The phase changed meanwhile (version {$phase->version}, expected {$command->expectedVersion}): reload it.", 'version');
        }

        return $phase;
    }

    /**
     * The instant the command takes effect: the client's own time when it replays an offline action, never in the
     * future and never before the last recorded event of the phase.
     */
    protected function effectiveAt(PhaseCommand $command, ?MfgMesPhase $phase): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $at = $command->occurredAt ?? $now;
        if ($at->greaterThan($now)) {
            $at = $now;
        }

        if ($phase instanceof MfgMesPhase) {
            $bound = CarbonImmutable::instance($phase->taken_over_at);
            foreach ($phase->timeLogs()->get() as $log) {
                $bound = $bound->max($log->started_at)->max($log->ended_at ?? $log->started_at);
            }
            if ($at->lessThan($bound)) {
                throw new ManufacturingOperationException('The action happened before the last recorded event of the phase.', 'occurred_at');
            }
        }

        return $at;
    }

    /**
     * Refuses an action the state machine does not allow from the phase's state.
     */
    protected function assertAllowed(PhaseAction $action, MfgMesPhase $phase): void
    {
        $action->assertAllowedFrom($phase->status);
    }

    /**
     * Refuses to start attended work for an operator who is already working another phase: an operator is in one
     * place at a time, while unattended runs can overlap.
     */
    protected function assertOperatorFree(MfgMesPhase $phase): void
    {
        if ($phase->operator_id === null) {
            return;
        }

        $busy = MfgMesPhase::query()
            ->where('tenant_id', $phase->tenant_id)
            ->where('operator_id', $phase->operator_id)
            ->whereKeyNot($phase->id)
            ->where(function ($query): void {
                $query->where('status', PhaseStatus::Setup->value)
                    ->orWhere(fn ($running) => $running->where('status', PhaseStatus::Running->value)->where('machine_mode', MachineMode::Attended->value));
            })
            ->exists();

        if ($busy) {
            throw new ManufacturingOperationException('The operator is already working another phase: suspend it first.', 'operator_id');
        }
    }

    /**
     * Ends the open interval of the phase at the given instant.
     */
    protected function closeInterval(MfgMesPhase $phase, CarbonImmutable $at): void
    {
        MfgMesTimeLog::query()->where('phase_id', $phase->id)->whereNull('ended_at')->update(['ended_at' => $at]);
    }

    /**
     * Opens an interval of the phase at the given instant.
     */
    protected function openInterval(MfgMesPhase $phase, TimeCategory $category, CarbonImmutable $at, bool $attended, ?SuspensionReason $reason = null): void
    {
        MfgMesTimeLog::create([
            'tenant_id' => $phase->tenant_id,
            'phase_id' => $phase->id,
            'operator_id' => $phase->operator_id,
            'category' => $category,
            'machine_attended' => $attended,
            'suspension_reason' => $reason,
            'started_at' => $at,
        ]);
    }

    /**
     * Saves the phase with the next version.
     */
    protected function save(MfgMesPhase $phase): void
    {
        $phase->version = $phase->version + 1;
        $phase->save();
    }

    /**
     * Publishes the transition of the phase.
     */
    protected function publishTransition(MfgMesPhase $phase, PhaseAction $action): void
    {
        $this->dispatcher->publishAndDispatch(new MesPhaseTransitionedEvent(
            phaseId: (string) $phase->id,
            productionOrderId: (string) $phase->production_order_id,
            productionOrderOperationId: (string) $phase->production_order_operation_id,
            action: $action->value,
            status: $phase->status->value,
            operatorId: $phase->operator_id,
            tenantId: (string) $phase->tenant_id,
        ));
    }
}
