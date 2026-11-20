<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Events;

/** Security event of the IAM engine: who (userId) did or suffered it in which tenant; `targetUserId` carries its detail. */
final readonly class ImpersonationStarted
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ?string $targetUserId = null,
        public ?string $actorId = null,
    ) {}
}
