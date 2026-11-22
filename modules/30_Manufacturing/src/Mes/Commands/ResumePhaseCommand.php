<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;

/**
 * Resumes a suspended phase (Italian: ripresa lavorazione) in the state it was suspended from.
 */
final readonly class ResumePhaseCommand extends PhaseCommand
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
        return 'resume';
    }
}
