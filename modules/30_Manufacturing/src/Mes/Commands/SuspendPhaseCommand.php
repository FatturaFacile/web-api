<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\SuspensionReason;

/**
 * Suspends a phase (Italian: sospensione) for a reason code; the time until the resume is downtime.
 */
final readonly class SuspendPhaseCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $phaseId,
        public SuspensionReason $reason,
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
        return 'suspend';
    }
}
