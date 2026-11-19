<?php

declare(strict_types=1);

namespace App\Domain\Audit\DTOs;

use App\Domain\Audit\Enums\AuditActorType;

final readonly class AuditActor
{
    public function __construct(
        public AuditActorType $type,
        public ?string $id,
        public ?string $email,
        public ?string $role,
        public ?string $ipAddress,
        public ?string $userAgent,
        public string $requestId,
        public ?string $routeName,
    ) {}
}
