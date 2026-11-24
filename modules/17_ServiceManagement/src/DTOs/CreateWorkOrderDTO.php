<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CreateWorkOrderDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $title,
        public ?string $description = null,
        public string $workType = 'corrective',
        public string $priority = 'medium',
        public ?string $serviceCategoryId = null,
        public ?string $customerId = null,
        public ?string $serviceContractId = null,
        public ?string $assetId = null,
        public ?string $ticketId = null,
        public ?string $projectId = null,
        public ?string $serviceTeamId = null,
        public ?string $leadTechnicianId = null,
        public ?string $scheduledStart = null,
        public ?string $scheduledEnd = null,
        public ?string $locationAddress = null,
        public string $billingType = 'contract_included',
        public array $items = []
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
