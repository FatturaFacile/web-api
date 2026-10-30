<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CreateTicketDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $title,
        public ?string $description = null,
        public ?string $customerId = null,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public string $priority = 'medium',
        public ?string $serviceCategoryId = null,
        public ?string $serviceContractId = null,
        public ?string $slaPolicyId = null,
        public string $channel = 'portal',
        public ?string $assignedToId = null,
        public ?string $serviceTeamId = null,
        public array $metadata = []
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
