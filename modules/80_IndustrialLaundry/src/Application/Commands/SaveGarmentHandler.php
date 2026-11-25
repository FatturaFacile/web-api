<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

use Modules\IndustrialLaundry\Models\Garment;
use Modules\IndustrialLaundry\Services\GarmentService;

final class SaveGarmentHandler
{
    public function __construct(private readonly GarmentService $garments) {}

    public function handle(SaveGarmentCommand $command): Garment
    {
        if ($command->garmentId === null) {
            return $this->garments->register($command->tenantId, $command->data, $command->userId);
        }

        return $this->garments->update(Garment::query()->findOrFail($command->garmentId), $command->data, $command->userId);
    }
}
