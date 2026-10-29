<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class CreateProjectDTO
{
    /**
     * @param  array<int, array{name: string, sequence_order: int, planned_start_date: string, planned_end_date: string}>  $phases
     * @param  array<int, array{name: string, milestone_date: string, billing_percentage?: float, billing_amount?: float}>  $milestones
     */
    public function __construct(
        public string $contactId,
        public string $projectNumber,
        public string $name,
        public string $projectType,
        public string $billingMethod,
        public string $plannedStartDate,
        public string $plannedEndDate,
        public string $projectManagerUserId,
        public float $contractValue = 0.00,
        public ?string $portfolioId = null,
        public ?string $description = null,
        public ?string $costCenterId = null,
        public ?string $parentProjectId = null,
        public array $phases = [],
        public array $milestones = [],
        public ?string $userId = null,
        public ?string $companyId = null
    ) {}
}
