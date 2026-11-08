<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * A project to open, with its optional initial phases and milestones.
 */
final readonly class CreateProjectDTO
{
    /**
     * @param  array<int, array{name: string, sequence_order?: int|null, planned_start_date?: string|null, planned_end_date?: string|null}>  $phases
     * @param  array<int, array{name: string, milestone_date: string, billing_percentage?: string|int|float|null, billing_amount?: string|int|float|null}>  $milestones
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
        public string $contractValue = '0',
        public ?string $portfolioId = null,
        public ?string $description = null,
        public ?string $costCenterId = null,
        public ?string $parentProjectId = null,
        public array $phases = [],
        public array $milestones = [],
        public ?string $userId = null,
        public ?string $companyId = null,
        public ?string $revenueRecognitionMethod = null,
        public string $currency = 'EUR',
    ) {}
}
