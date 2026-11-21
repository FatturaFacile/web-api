<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Events;

/**
 * A safety certificate entered a 60/30/7 day window before its expiry.
 */
final readonly class SafetyCertificateExpiringEvent
{
    public function __construct(
        public string $tenantId,
        public string $certificateId,
        public string $employeeId,
        public string $certificateType,
        public string $expiresAt,
        public int $daysLeft,
    ) {}
}
