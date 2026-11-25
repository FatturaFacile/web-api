<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

final readonly class SubcontractServiceExchangeCommand
{
    /**
     * @param  string  $action  cede, acquire or release
     * @param  string|null  $amount  payout of the colleague (cede) or commission of the assignor (acquire)
     */
    public function __construct(
        public string $bookingId,
        public string $action,
        public ?string $carrierId,
        public ?string $amount,
        public ?string $userId,
    ) {}
}
