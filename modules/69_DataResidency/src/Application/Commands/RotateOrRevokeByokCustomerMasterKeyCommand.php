<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Commands;

use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;

final readonly class RotateOrRevokeByokCustomerMasterKeyCommand
{
    public function __construct(
        public string $tenantId,
        public string $envelopeId,
        public string $actorId,
        public KeyLifecycleAction $action,
        public ?string $reason = null,
        public ?string $sourceIp = null,
    ) {}
}
