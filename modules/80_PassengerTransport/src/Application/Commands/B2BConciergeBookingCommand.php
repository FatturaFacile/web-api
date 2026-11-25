<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

final readonly class B2BConciergeBookingCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $contractId,
        public array $data,
        public string $tenantId,
        public ?string $userId,
    ) {}
}
