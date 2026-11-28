<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Events;

/**
 * Rental equipment was attached to a lesson or an enrolment: the rental / inventory hook listens to this event.
 */
final readonly class PackageEquipmentReserved
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public array $equipment,
    ) {}
}
