<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class RecordVisitDTO
{
    public function __construct(
        public string $workOrderId,
        public ?string $serviceAppointmentId = null,
        public string $technicianId = '',
        public string $checkInAt = '',
        public ?string $checkOutAt = null,
        public ?string $workSummary = null,
        public ?string $customerFeedback = null
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
