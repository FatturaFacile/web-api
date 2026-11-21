<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\MachineMode;

/**
 * Starts machining (Italian: inizio lavorazione), attended or unattended; on a running phase it switches the mode.
 */
final readonly class StartProductionCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $phaseId,
        public MachineMode $machineMode = MachineMode::Attended,
        ?int $expectedVersion = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($tenantId, $actorUserId, $idempotencyKey, $expectedVersion, $occurredAt);
    }

    /**
     * Name of the command in the receipts.
     */
    public function name(): string
    {
        return 'start_production';
    }
}
