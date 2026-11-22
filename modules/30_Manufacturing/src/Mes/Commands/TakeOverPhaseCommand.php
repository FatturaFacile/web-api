<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;

/**
 * An operator takes over (Italian: presa in carico) a phase: the first take-over of an operation creates the phase, a
 * later one hands it to another operator, as at a shift handover.
 */
final readonly class TakeOverPhaseCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $operationId,
        public string $operatorId,
        public ?string $machineId = null,
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
        return 'take_over';
    }
}
