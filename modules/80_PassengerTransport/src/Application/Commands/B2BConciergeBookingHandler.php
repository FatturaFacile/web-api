<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Modules\PassengerTransport\Models\HotelConciergeContract;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\ConciergeBookingService;

final class B2BConciergeBookingHandler
{
    public function __construct(private readonly ConciergeBookingService $concierge) {}

    public function handle(B2BConciergeBookingCommand $command): PassengerBooking
    {
        return $this->concierge->book(HotelConciergeContract::query()->findOrFail($command->contractId), $command->data, $command->tenantId, $command->userId);
    }
}
