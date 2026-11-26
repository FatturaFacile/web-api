<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\ValueObjects;

use Enterprise\AuditLedger\Domain\Enums\ActorType;

/**
 * Who acted: the type, the pseudonym of the actor (null for the system) and, when somebody acted on behalf of another
 * person (impersonation), the pseudonym of the real operator.
 */
final readonly class ActorRef
{
    public function __construct(
        public ActorType $type,
        public ?string $ref = null,
        public ?string $impersonatorRef = null,
        public ?string $userId = null,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System);
    }
}
