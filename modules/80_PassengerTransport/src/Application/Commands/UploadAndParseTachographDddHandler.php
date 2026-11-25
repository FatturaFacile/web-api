<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Modules\PassengerTransport\Models\DddArchive;
use Modules\PassengerTransport\Services\TachographService;

final class UploadAndParseTachographDddHandler
{
    public function __construct(private readonly TachographService $tachograph) {}

    public function handle(UploadAndParseTachographDddCommand $command): DddArchive
    {
        return $this->tachograph->upload($command->file, $command->driverEmployeeId, $command->vehicleId, $command->userId);
    }
}
