<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class ScheduleAppointmentDTO
{
    public function __construct(
        public string $workOrderId,
        public string $technicianId,
        public string $scheduledStart,
        public string $scheduledEnd,
        public ?string $notes = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
