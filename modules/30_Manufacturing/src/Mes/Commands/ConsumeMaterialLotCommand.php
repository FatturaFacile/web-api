<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;

/**
 * Registers the consumption of a component from a lot (Italian: materia prima utilizzata, lotto) on a phase. Consuming
 * more than the order requires needs the operator's explicit confirmation.
 */
final readonly class ConsumeMaterialLotCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $phaseId,
        public string $mfgItemId,
        public ?string $lotCode,
        public string $quantity,
        public bool $overConsumptionConfirmed = false,
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
        return 'consume_material';
    }
}
