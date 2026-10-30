<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CreateIncidentDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $title,
        public ?string $description = null,
        public string $severity = 'medium',
        public string $impact = 'moderate',
        public string $urgency = 'medium',
        public ?string $serviceCategoryId = null,
        public ?string $affectedAssetId = null,
        public ?string $reportedById = null
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
