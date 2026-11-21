<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Events;

final readonly class SafetyCertificateExpiredEvent
{
    public function __construct(
        public string $tenantId,
        public string $certificateId,
        public string $employeeId,
        public string $certificateType,
        public string $expiredAt,
    ) {}
}
