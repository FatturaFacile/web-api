<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;

/**
 * Base of the MES commands (CQRS write side). Every command carries the client generated idempotency key that makes a
 * network retry harmless, the optional optimistic concurrency version the client last saw and, for offline replay, the
 * instant the operator really acted.
 */
abstract readonly class PhaseCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $actorUserId,
        public string $idempotencyKey,
        public ?int $expectedVersion = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    /**
     * Name of the command in the receipts.
     */
    abstract public function name(): string;
}
