<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Events;

/** Security event of the IAM engine: who (userId) did or suffered it in which tenant; `method` carries its detail. */
final readonly class MfaDisabled
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ?string $method = null,
        public ?string $actorId = null,
    ) {}
}
