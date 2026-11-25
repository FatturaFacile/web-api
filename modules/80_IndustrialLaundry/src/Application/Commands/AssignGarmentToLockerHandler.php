<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Services\LockerService;

final class AssignGarmentToLockerHandler
{
    public function __construct(private readonly LockerService $lockers) {}

    public function handle(AssignGarmentToLockerCommand $command): Garment
    {
        $garment = Garment::query()->findOrFail($command->garmentId);

        if ($command->employeeName === null || $command->employeeName === '') {
            return $this->lockers->release($garment, $command->userId);
        }

        return $this->lockers->assign($garment, $command->employeeName, (string) $command->lockerCode, $command->customerId, $command->userId);
    }
}
