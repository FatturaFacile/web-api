<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Mes\Commands\StartSetupCommand;
use Modules\Manufacturing\Models\MfgMesPhase;

/**
 * Starts the setup of a taken-over phase (Italian: avvio setup): operator and machine are both busy.
 */
final class StartSetupHandler extends PhaseCommandHandler
{
    /**
     * Handles the command and returns the phase.
     */
    public function handle(StartSetupCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::StartSetup, $phase);
            $this->assertOperatorFree($phase);
            $at = $this->effectiveAt($command, $phase);

            $this->openInterval($phase, TimeCategory::Setup, $at, true);
            $phase->status = PhaseStatus::Setup;
            $this->save($phase);
            $this->publishTransition($phase, PhaseAction::StartSetup);

            return $phase;
        });
    }
}
