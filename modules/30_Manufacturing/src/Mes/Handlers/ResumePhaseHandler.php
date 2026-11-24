<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Mes\Commands\ResumePhaseCommand;
use Modules\Manufacturing\Models\MfgMesPhase;

/**
 * Resumes a suspended phase (Italian: ripresa lavorazione) in the state it was suspended from.
 */
final class ResumePhaseHandler extends PhaseCommandHandler
{
    /**
     * Handles the command and returns the phase.
     */
    public function handle(ResumePhaseCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::Resume, $phase);
            $target = $phase->resume_to ?? PhaseStatus::Running;
            $attended = $target === PhaseStatus::Setup || $phase->machine_mode === MachineMode::Attended;
            if ($attended) {
                $this->assertOperatorFree($phase);
            }
            $at = $this->effectiveAt($command, $phase);

            $this->closeInterval($phase, $at);
            $this->openInterval($phase, $target === PhaseStatus::Setup ? TimeCategory::Setup : TimeCategory::Run, $at, $attended);
            $phase->status = $target;
            $phase->resume_to = null;
            $this->save($phase);
            $this->publishTransition($phase, PhaseAction::Resume);

            return $phase;
        });
    }
}
