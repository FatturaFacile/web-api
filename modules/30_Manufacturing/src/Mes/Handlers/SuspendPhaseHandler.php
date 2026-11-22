<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Mes\Commands\SuspendPhaseCommand;
use Modules\Manufacturing\Models\MfgMesPhase;

/**
 * Suspends a phase in setup or run (Italian: sospensione) and remembers the state to resume.
 */
final class SuspendPhaseHandler extends PhaseCommandHandler
{
    /**
     * Handles the command and returns the phase.
     */
    public function handle(SuspendPhaseCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::Suspend, $phase);
            $at = $this->effectiveAt($command, $phase);

            $this->closeInterval($phase, $at);
            $this->openInterval($phase, TimeCategory::Suspension, $at, false, $command->reason);
            $phase->resume_to = $phase->status;
            $phase->status = PhaseStatus::Suspended;
            $this->save($phase);
            $this->publishTransition($phase, PhaseAction::Suspend);

            return $phase;
        });
    }
}
