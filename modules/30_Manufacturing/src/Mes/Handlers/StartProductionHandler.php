<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Handlers;

use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Commands\StartProductionCommand;
use Modules\Manufacturing\Models\MfgMesPhase;

/**
 * Starts machining (Italian: inizio lavorazione) after the take-over or the setup, or switches a running phase between
 * attended and unattended.
 */
final class StartProductionHandler extends PhaseCommandHandler
{
    /**
     * Handles the command and returns the phase.
     */
    public function handle(StartProductionCommand $command): MfgMesPhase
    {
        return $this->execute($command, function () use ($command): MfgMesPhase {
            $phase = $this->lockPhase($command, $command->phaseId);
            $this->assertAllowed(PhaseAction::StartProduction, $phase);
            if ($phase->status === PhaseStatus::Running && $phase->machine_mode === $command->machineMode) {
                throw new ManufacturingOperationException("The phase already runs {$command->machineMode->value}.", 'machine_mode');
            }

            $phase->machine_mode = $command->machineMode;
            if ($command->machineMode === MachineMode::Attended) {
                $this->assertOperatorFree($phase);
            }
            $at = $this->effectiveAt($command, $phase);

            $this->closeInterval($phase, $at);
            $this->openInterval($phase, TimeCategory::Run, $at, $command->machineMode === MachineMode::Attended);
            $phase->status = PhaseStatus::Running;
            $this->save($phase);
            $this->publishTransition($phase, PhaseAction::StartProduction);

            return $phase;
        });
    }
}
