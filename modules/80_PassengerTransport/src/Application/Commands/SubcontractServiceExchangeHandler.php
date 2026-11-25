<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\SubcontractService;

final class SubcontractServiceExchangeHandler
{
    public function __construct(private readonly SubcontractService $subcontracts) {}

    public function handle(SubcontractServiceExchangeCommand $command): PassengerBooking
    {
        $booking = PassengerBooking::query()->findOrFail($command->bookingId);

        return match ($command->action) {
            'cede' => $this->subcontracts->cede($booking, $command->carrierId ?? throw new PassengerTransportException('subcontract_carrier_required', 422), $command->amount ?? '0', $command->userId),
            'acquire' => $this->subcontracts->acquire($booking, $command->carrierId ?? throw new PassengerTransportException('subcontract_carrier_required', 422), $command->amount ?? '0', $command->userId),
            'release' => $this->subcontracts->release($booking, $command->userId),
            default => throw new PassengerTransportException('subcontract_action_unknown', 422, ['action' => $command->action]),
        };
    }
}
