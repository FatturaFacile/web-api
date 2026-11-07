<?php

declare(strict_types=1);

namespace Modules\Payroll\Swissdec;

/**
 * What a transmitter returns once the distributor accepted a declaration.
 */
final readonly class TransmissionReceipt
{
    public function __construct(
        public string $declarationId,
        public string $receivedAt,
    ) {}
}
