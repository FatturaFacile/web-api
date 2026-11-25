<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

/**
 * Garment -> employee -> locker. A null employee releases the garment.
 */
final readonly class AssignGarmentToLockerCommand
{
    public function __construct(
        public string $garmentId,
        public ?string $employeeName,
        public ?string $lockerCode,
        public ?string $customerId,
        public ?string $userId,
    ) {}
}
