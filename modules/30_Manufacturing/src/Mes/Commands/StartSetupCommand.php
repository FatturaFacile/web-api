<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;

/**
 * Starts the setup or changeover (Italian: avvio setup) of a taken-over phase.
 */
final readonly class StartSetupCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $phaseId,
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
        return 'start_setup';
    }
}
