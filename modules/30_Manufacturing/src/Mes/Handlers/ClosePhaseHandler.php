<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Mes\Commands\ClosePhaseCommand;
use Modules\Manufacturing\Mes\Time\PhaseTimeCalculator;
use Modules\Manufacturing\Mes\Time\PhaseTimeSummary;
use Modules\Manufacturing\Models\MfgMesPhase;
use Modules\Manufacturing\Models\MfgMesScrapDeclaration;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Services\ProductionExecutionService;
use Modules\Manufacturing\Support\Money;

/**
 * Closes a phase (Italian: chiusura fase): ends the open interval and confirms the operation as completed with the
 * setup and run minutes of the machine, the man minutes as labour, the downtime and the declared pieces, so costing,
 * WIP and backflush follow the same rules as any other confirmation.
 */
final class ClosePhaseHandler extends PhaseCommandHandler
{
    public function __construct(
        EventDispatcher $dispatcher,
        private readonly ProductionExecutionService $execution,
        private readonly PhaseTimeCalculator $calculator,
    ) {
        parent::__construct($dispatcher);
    }

    /**
     * Handles the command and returns the phase.
     */
    public function handle(ClosePhaseCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::Close, $phase);
            $at = $this->effectiveAt($command, $phase);

            $order = $this->execution->lockOpen(MfgProductionOrder::query()->where('tenant_id', $command->tenantId)->findOrFail($phase->production_order_id));
            $operation = MfgProductionOrderOperation::query()->whereKey($phase->production_order_operation_id)->lockForUpdate()->firstOrFail();

            $this->closeInterval($phase, $at);
            $summary = $this->calculator->summarize($phase->timeLogs()->get(), $at);

            $good = '0';
            $scrap = '0';
            foreach ($phase->declarations()->get() as $declaration) {
                $good = Money::add($good, $declaration->good_quantity);
                $scrap = Money::add($scrap, $declaration->scrap_quantity);
            }
            $reasons = MfgMesScrapDeclaration::query()->where('phase_id', $phase->id)->get()->map(static fn (MfgMesScrapDeclaration $row): string => $row->defect_reason->value)->unique()->implode(', ');

            $confirmation = $this->execution->recordConfirmation($order, $operation, new ConfirmOperationDTO(
                setupMinutes: PhaseTimeSummary::minutes($summary->setupSeconds),
                runMinutes: PhaseTimeSummary::minutes($summary->runSeconds()),
                laborMinutes: PhaseTimeSummary::minutes($summary->manSeconds()),
                goodQuantity: $good,
                scrapQuantity: $scrap,
                scrapReason: $reasons !== '' ? $reasons : null,
                isFinal: true,
                postingDate: $command->postingDate,
                downtimeMinutes: PhaseTimeSummary::minutes($summary->downtimeSeconds),
            ), '0', null, $command->actorUserId);

            $phase->status = PhaseStatus::Closed;
            $phase->resume_to = null;
            $phase->closed_at = $at;
            $phase->confirmation_id = (string) $confirmation->id;
            $this->save($phase);
            $this->publishTransition($phase, PhaseAction::Close);

            return $phase;
        });
    }
}
