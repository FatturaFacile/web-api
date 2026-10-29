<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class CreateChangeRequestDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $title,
        public ?string $description = null,
        public string $changeType = "standard",
        public string $riskLevel = "medium",
        public string $priority = "medium",
        public ?string $serviceCategoryId = null,
        public ?string $rolloutPlan = null,
        public ?string $rollbackPlan = null,
        public ?string $testPlan = null,
        public ?string $scheduledStartTime = null,
        public ?string $scheduledEndTime = null
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
