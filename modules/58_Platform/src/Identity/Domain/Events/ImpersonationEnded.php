<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Domain\Events;

/** Security event of the IAM engine: an impersonation of `targetUserId` ended (closed by the actor). */
final readonly class ImpersonationEnded
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ?string $targetUserId = null,
        public ?string $actorId = null,
    ) {}
}
